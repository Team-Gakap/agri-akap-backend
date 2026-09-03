<?php

namespace App\Http\Controllers;

use App\Models\DamageAssessment;
use App\Models\Distribution;
use App\Models\Farmer;
use App\Models\Program;
use App\Support\AuditRemarks;
use App\Services\SystemAuditLogger;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ReportExportController extends Controller
{
    /**
     * Stream a filtered CSV export.
     * Supported types: farmers | distributions | damage | accomplishment
     * Optional filters: ?barangay=&commodity=&date_from=&date_to=
     */
    public function export(Request $request, string $type): StreamedResponse
    {
        $filters = [
            'barangay' => $request->query('barangay'),
            'commodity' => $request->query('commodity'),
            'date_from' => $request->query('date_from'),
            'date_to' => $request->query('date_to'),
            'search' => $request->query('search'),
            'verification_status' => $request->query('verification_status'),
        ];

        [$headers, $rows] = match ($type) {
            'farmers' => $this->farmers($filters),
            'distributions' => $this->distributions($filters),
            'damage' => $this->damage($filters),
            'accomplishment' => $this->accomplishment($filters),
            default => abort(404, 'Unknown report type.'),
        };

        if (in_array($type, ['farmers', 'distributions'], true)) {
            $remarks = AuditRemarks::require($request, 'A justification is required before exporting farmer or subsidy data.');
        } else {
            $remarks = AuditRemarks::optional($request);
        }

        app(SystemAuditLogger::class)->record('export.'.$type, $request->user(), null, [
            'filters' => $filters,
            'row_count' => is_countable($rows) ? count($rows) : null,
            'remarks' => $remarks,
        ], $request);

        $filename = "agri-akap-{$type}-" . now()->format('Ymd-His') . '.csv';

        return response()->streamDownload(function () use ($headers, $rows) {
            $out = fopen('php://output', 'w');
            // UTF-8 BOM so Excel reads accents correctly
            fwrite($out, "\xEF\xBB\xBF");
            fputcsv($out, $headers);
            foreach ($rows as $row) {
                fputcsv($out, $row);
            }
            fclose($out);
        }, $filename, [
            'Content-Type' => 'text/csv; charset=UTF-8',
        ]);
    }

    private function farmers(array $f): array
    {
        $headers = [
            'RSBSA No', 'Surname', 'First Name', 'Middle Name', 'Ext', 'Sex',
            'Birthdate', 'Mobile', 'Barangay', 'City', 'Province', 'Civil Status',
            'PWD', '4Ps', 'Livelihood', 'Farm Plots',
        ];

        $rows = Farmer::withCount('farmPlots')
            ->when($f['barangay'], fn ($q) => $q->where('permanent_brgy', $f['barangay']))
            ->when($f['search'] ?? null, function ($q, $term) {
                $q->search($term);
            })
            ->when($f['verification_status'] ?? null, function ($q, $status) {
                if (in_array($status, ['pending', 'approved', 'rts'], true)) {
                    $q->where('verification_status', $status);
                }
            })
            ->when($f['commodity'] ?? null, function ($q, $commodity) {
                $key = strtolower((string) $commodity);
                $q->whereHas('farmPlots', function ($plots) use ($key) {
                    if (in_array($key, ['high-value', 'high-value crops', 'hvc'], true)) {
                        $plots->where(function ($inner) {
                            $inner->whereRaw('LOWER(commodity) like ?', ['%high-value%'])
                                ->orWhereRaw('LOWER(commodity) like ?', ['%hvc%']);
                        });
                        return;
                    }
                    $plots->whereRaw('LOWER(commodity) = ?', [$key]);
                });
            })
            ->orderBy('surname')
            ->get()
            ->map(fn ($x) => [
                $x->rsbsa_no,
                $x->surname,
                $x->first_name,
                $x->middle_name,
                $x->ext_name,
                $x->sex,
                optional($x->birthdate)->format('Y-m-d'),
                $x->mobile_number,
                $x->permanent_brgy,
                $x->permanent_city,
                $x->permanent_province,
                $x->civil_status,
                $x->is_pwd ? 'Yes' : 'No',
                $x->is_4ps_beneficiary ? 'Yes' : 'No',
                $x->livelihood_type,
                $x->farm_plots_count,
            ]);

        return [$headers, $rows];
    }

    private function distributions(array $f): array
    {
        $headers = [
            'Date', 'Program', 'Type', 'Farmer', 'Barangay', 'Quantity', 'Unit', 'Technician', 'Status',
        ];

        $rows = Distribution::with([
            'farmer:id,first_name,surname,permanent_brgy',
            'program:id,name,type,unit_of_measurement',
            'technician:id,name',
        ])
            ->when($f['barangay'], fn ($q) => $q->whereHas('farmer', fn ($sub) => $sub->where('permanent_brgy', $f['barangay'])))
            ->when($f['date_from'], fn ($q) => $q->whereDate('claimed_at', '>=', $f['date_from']))
            ->when($f['date_to'], fn ($q) => $q->whereDate('claimed_at', '<=', $f['date_to']))
            ->orderByDesc('claimed_at')
            ->get()
            ->map(fn ($d) => [
                optional($d->claimed_at)->format('Y-m-d H:i'),
                optional($d->program)->name,
                optional($d->program)->type,
                trim((optional($d->farmer)->first_name ?? '') . ' ' . (optional($d->farmer)->surname ?? '')),
                optional($d->farmer)->permanent_brgy,
                $d->quantity_claimed,
                optional($d->program)->unit_of_measurement,
                optional($d->technician)->name,
                $d->status,
            ]);

        return [$headers, $rows];
    }

    private function damage(array $f): array
    {
        $headers = [
            'Farmer', 'Barangay', 'Calamity Type', 'Calamity', 'Date of Calamity',
            'Commodity', 'Area (ha)', 'Area Destroyed (ha)', 'Crop Stage',
            'Damage %', 'Est. Value Lost (PHP)', 'Status',
        ];

        $rows = DamageAssessment::with([
            'farmer:id,first_name,surname,permanent_brgy',
            'farmPlot:id,commodity,size_ha,location_brgy',
        ])
            ->where('status', 'Approved')
            ->when($f['barangay'], function ($q) use ($f) {
                $q->where(function ($sub) use ($f) {
                    $sub->whereHas('farmPlot', fn ($fp) => $fp->where('location_brgy', $f['barangay']))
                        ->orWhereHas('farmer', fn ($fa) => $fa->where('permanent_brgy', $f['barangay']));
                });
            })
            ->when($f['commodity'], fn ($q) => $q->whereHas('farmPlot', fn ($fp) => $fp->where('commodity', $f['commodity'])))
            ->when($f['date_from'], fn ($q) => $q->whereDate('date_of_calamity', '>=', $f['date_from']))
            ->when($f['date_to'], fn ($q) => $q->whereDate('date_of_calamity', '<=', $f['date_to']))
            ->orderByDesc('date_of_calamity')
            ->get()
            ->map(fn ($a) => [
                trim((optional($a->farmer)->first_name ?? '') . ' ' . (optional($a->farmer)->surname ?? '')),
                optional($a->farmPlot)->location_brgy ?? optional($a->farmer)->permanent_brgy,
                $a->calamity_type,
                $a->calamity_name,
                optional($a->date_of_calamity)->format('Y-m-d'),
                optional($a->farmPlot)->commodity,
                optional($a->farmPlot)->size_ha,
                $a->area_destroyed_ha,
                $a->crop_stage,
                $a->damage_percentage,
                $a->estimated_value_lost,
                $a->status,
            ]);

        return [$headers, $rows];
    }

    private function accomplishment(array $f): array
    {
        $headers = [
            'Program', 'Type', 'Total Qty', 'Dispensed', 'Remaining', 'Beneficiaries',
            'Unit', 'Funding Source', 'Start', 'End', 'Active',
        ];

        $rows = Program::withCount('distributions')
            ->orderBy('name')
            ->get()
            ->map(fn ($p) => [
                $p->name,
                $p->type,
                $p->total_quantity,
                $p->total_quantity - $p->remaining_quantity,
                $p->remaining_quantity,
                $p->distributions_count,
                $p->unit_of_measurement,
                $p->funding_source,
                optional($p->start_date)->format('Y-m-d'),
                optional($p->end_date)->format('Y-m-d'),
                $p->is_active ? 'Yes' : 'No',
            ]);

        return [$headers, $rows];
    }
}
