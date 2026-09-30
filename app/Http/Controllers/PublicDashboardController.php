<?php

namespace App\Http\Controllers;

use App\Models\Distribution;
use App\Models\Farmer;
use App\Models\FarmPlot;
use App\Models\SubsidyBeneficiary;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Guest-facing aggregated KPIs only — no PII, lists, or mutation endpoints.
 */
class PublicDashboardController extends Controller
{
    public function summary(Request $request): JsonResponse
    {
        $filters = $request->validate([
            'barangay' => ['nullable', 'string', 'max:120'],
            'commodity' => ['nullable', 'string', 'max:64'],
            'year' => ['nullable', 'integer', 'between:2000,' . (Carbon::now()->year + 1)],
        ]);

        $farmers = $this->filteredFarmers($filters)->get([
            'id', 'sex', 'birthdate', 'is_pwd', 'permanent_brgy', 'rsbsa_no',
        ]);
        $gender = $this->farmerGenderAndPwd($farmers);
        $demographics = $this->demographicBreakdown($farmers);
        $barangayRanking = $this->barangayInclusionRanking($farmers);
        $farmArea = $this->farmAreaByCommodity($filters);
        $distributionData = $this->distributionBreakdown($filters);
        $subsidy = $this->subsidyUptake($filters);
        $peak = $this->peakDisbursementDays();

        return response()->json([
            'data' => [
                'total_farmers' => $farmers->count(),
                'farmers_male' => $gender['male'],
                'farmers_female' => $gender['female'],
                'pwd_male' => $gender['pwd_male'],
                'pwd_female' => $gender['pwd_female'],
                'pwd_total' => $gender['pwd_male'] + $gender['pwd_female'],
                'rsbsa_verified' => $gender['rsbsa_verified'],
                'subsidy_uptake_percent' => $subsidy['uptake_percent'],
                'subsidy_beneficiaries_claimed' => $subsidy['beneficiaries_claimed'],
                'subsidy_beneficiaries_enrolled' => $subsidy['beneficiaries_enrolled'],
                'peak_disbursement' => $peak,
                'senior_total' => $gender['senior_total'],
                'senior_male' => $gender['senior_male'],
                'senior_female' => $gender['senior_female'],
                'female_percent' => $gender['female_percent'],
                'age_distribution' => $demographics,
                'priority_groups' => $gender['priority_groups'],
                'claim_breakdown' => $distributionData['claims'],
                'farm_area_by_commodity' => $farmArea,
                'barangay_inclusion' => $barangayRanking,
                'recent_activity' => $distributionData['recent_activity'],
                'filters' => [
                    'barangays' => Farmer::query()
                        ->whereNotNull('permanent_brgy')
                        ->where('permanent_brgy', '!=', '')
                        ->distinct()
                        ->orderBy('permanent_brgy')
                        ->pluck('permanent_brgy')
                        ->values(),
                    'years' => range(2022, Carbon::now()->year),
                ],
                'applied_filters' => [
                    'barangay' => $filters['barangay'] ?? null,
                    'commodity' => $filters['commodity'] ?? null,
                    'year' => isset($filters['year']) ? (int) $filters['year'] : null,
                ],
                'data_notes' => [
                    'year' => 'Year filters distribution claims; farmer and land profiles reflect the current registry.',
                    'gender' => 'Records store sex as Male or Female; gender identity is not collected.',
                    'priority' => 'Senior and PWD classifications may overlap; the chart uses mutually exclusive groups.',
                    'gad_budget' => 'GAD budget utilization is not recorded in this dashboard.',
                ],
                'municipality' => 'Echague, Isabela',
                'office' => 'Municipal Agriculture Office',
            ],
        ]);
    }

    /**
     * @return array{male: int, female: int, pwd_male: int, pwd_female: int, rsbsa_verified: int}
     */
    private function farmerGenderAndPwd($farmers): array
    {
        $male = 0;
        $female = 0;
        $pwdMale = 0;
        $pwdFemale = 0;
        $seniorMale = 0;
        $seniorFemale = 0;
        $pwdSeniors = 0;
        $rsbsa = 0;

        foreach ($farmers as $farmer) {
            $isSenior = Carbon::parse($farmer->birthdate)->age >= 60;
            $isPwd = (bool) $farmer->is_pwd;
            $rsbsa += filled($farmer->rsbsa_no) ? 1 : 0;

            if ($farmer->sex === 'Male') {
                $male++;
                $pwdMale += $isPwd ? 1 : 0;
                $seniorMale += $isSenior ? 1 : 0;
            } else {
                $female++;
                $pwdFemale += $isPwd ? 1 : 0;
                $seniorFemale += $isSenior ? 1 : 0;
            }

            $pwdSeniors += $isSenior && $isPwd ? 1 : 0;
        }

        return [
            'male' => $male,
            'female' => $female,
            'pwd_male' => $pwdMale,
            'pwd_female' => $pwdFemale,
            'senior_male' => $seniorMale,
            'senior_female' => $seniorFemale,
            'senior_total' => $seniorMale + $seniorFemale,
            'female_percent' => $male + $female > 0 ? round(($female / ($male + $female)) * 100, 1) : 0.0,
            'priority_groups' => [
                'senior' => $seniorMale + $seniorFemale,
                'pwd' => $pwdMale + $pwdFemale - $pwdSeniors,
                'regular' => count($farmers) - ($seniorMale + $seniorFemale) - ($pwdMale + $pwdFemale - $pwdSeniors),
                'senior_pwd_overlap' => $pwdSeniors,
            ],
            'rsbsa_verified' => $rsbsa,
        ];
    }

    private function filteredFarmers(array $filters)
    {
        return Farmer::query()
            ->when($filters['barangay'] ?? null, fn ($query, $barangay) => $query->where('permanent_brgy', $barangay))
            ->when($filters['commodity'] ?? null, fn ($query, $commodity) => $query->whereHas(
                'farmPlots',
                fn ($plots) => $plots->whereRaw('LOWER(commodity) = ?', [strtolower($commodity)])
            ));
    }

    private function demographicBreakdown($farmers): array
    {
        $groups = [
            '18-30' => ['male' => 0, 'female' => 0],
            '31-45' => ['male' => 0, 'female' => 0],
            '46-59' => ['male' => 0, 'female' => 0],
            '60+' => ['male' => 0, 'female' => 0],
        ];

        foreach ($farmers as $farmer) {
            $age = Carbon::parse($farmer->birthdate)->age;
            if ($age < 18) {
                continue;
            }
            $bracket = match (true) {
                $age <= 30 => '18-30',
                $age <= 45 => '31-45',
                $age <= 59 => '46-59',
                default => '60+',
            };
            $sex = $farmer->sex === 'Female' ? 'female' : 'male';
            $groups[$bracket][$sex]++;
        }

        return collect($groups)->map(fn ($counts, $label) => ['age_group' => $label] + $counts)->values()->all();
    }

    private function barangayInclusionRanking($farmers): array
    {
        return $farmers
            ->groupBy(fn ($farmer) => trim((string) $farmer->permanent_brgy) ?: 'Unspecified')
            ->map(function ($rows, $barangay) {
                $total = $rows->count();
                $female = $rows->where('sex', 'Female')->count();
                $pwd = $rows->where('is_pwd', true)->count();

                return [
                    'barangay' => $barangay,
                    'farmers' => $total,
                    'female_percent' => $total ? round(($female / $total) * 100, 1) : 0,
                    'pwd_percent' => $total ? round(($pwd / $total) * 100, 1) : 0,
                ];
            })
            ->sortByDesc(fn ($row) => $row['female_percent'] + $row['pwd_percent'])
            ->take(8)
            ->values()
            ->all();
    }

    private function farmAreaByCommodity(array $filters): array
    {
        return FarmPlot::query()
            ->with('farmer:id,sex')
            ->whereHas('farmer', fn ($farmers) => $farmers
                ->when($filters['barangay'] ?? null, fn ($query, $barangay) => $query->where('permanent_brgy', $barangay)))
            ->when($filters['commodity'] ?? null, fn ($query, $commodity) => $query->whereRaw(
                'LOWER(commodity) = ?', [strtolower($commodity)]
            ))
            ->get(['commodity', 'size_ha', 'farmer_id'])
            ->filter(fn ($plot) => $plot->farmer)
            ->groupBy(fn ($plot) => ucfirst(strtolower($plot->commodity)))
            ->map(fn ($plots, $commodity) => [
                'commodity' => $commodity,
                'male_hectares' => round((float) $plots->filter(fn ($plot) => $plot->farmer->sex === 'Male')->sum('size_ha'), 2),
                'female_hectares' => round((float) $plots->filter(fn ($plot) => $plot->farmer->sex === 'Female')->sum('size_ha'), 2),
            ])
            ->values()
            ->all();
    }

    private function distributionBreakdown(array $filters): array
    {
        $rows = Distribution::query()
            ->with('farmer:id,sex,birthdate,is_pwd')
            ->whereHas('farmer', fn ($farmers) => $farmers
                ->when($filters['barangay'] ?? null, fn ($query, $barangay) => $query->where('permanent_brgy', $barangay))
                ->when($filters['commodity'] ?? null, fn ($query, $commodity) => $query->whereHas(
                    'farmPlots',
                    fn ($plots) => $plots->whereRaw('LOWER(commodity) = ?', [strtolower($commodity)])
                )))
            ->when($filters['year'] ?? null, fn ($query, $year) => $query->whereYear('claimed_at', $year))
            ->get(['id', 'farmer_id', 'status', 'claimed_at']);

        $claims = [
            'male' => ['claimed' => 0, 'pending' => 0],
            'female' => ['claimed' => 0, 'pending' => 0],
            'pwd' => ['claimed' => 0, 'pending' => 0],
            'senior' => ['claimed' => 0, 'pending' => 0],
        ];
        $activity = [];
        foreach ($rows as $distribution) {
            if (! $distribution->farmer) {
                continue;
            }

            $status = $distribution->status === 'pending_sync' ? 'pending' : 'claimed';
            $sex = $distribution->farmer->sex === 'Female' ? 'female' : 'male';
            $claims[$sex][$status]++;
            $isSenior = Carbon::parse($distribution->farmer->birthdate)->age >= 60;
            $isPwd = (bool) $distribution->farmer->is_pwd;
            if ($isSenior) {
                $claims['senior'][$status]++;
            } elseif ($isPwd) {
                $claims['pwd'][$status]++;
            }

            $date = Carbon::parse($distribution->claimed_at)->toDateString();
            $activity[$date] ??= ['date' => $date, 'claimed' => 0, 'pending' => 0];
            $activity[$date][$status]++;
        }

        krsort($activity);
        return [
            'claims' => $claims,
            'recent_activity' => array_slice(array_values($activity), 0, 8),
        ];
    }

    /**
     * @return array{uptake_percent: float, beneficiaries_claimed: int, beneficiaries_enrolled: int}
     */
    private function subsidyUptake(array $filters): array
    {
        $claimed = 0;
        $enrolled = 0;

        if (Schema::hasTable('tbl_subsidy_beneficiaries') && Schema::hasTable('tbl_subsidy_programs')) {
            $enrolledQuery = DB::table('tbl_subsidy_beneficiaries')
                ->join('farmers', 'farmers.rsbsa_no', '=', 'tbl_subsidy_beneficiaries.farmer_rsbsa_no')
                ->join('tbl_subsidy_programs', 'tbl_subsidy_programs.id', '=', 'tbl_subsidy_beneficiaries.program_id')
                ->whereNull('farmers.deleted_at')
                ->when($filters['barangay'] ?? null, fn ($query, $barangay) => $query->where('farmers.permanent_brgy', $barangay))
                ->when($filters['commodity'] ?? null, fn ($query, $commodity) => $query->where('tbl_subsidy_programs.target_crop', $commodity));
            SubsidyBeneficiary::applyNotDeleted($enrolledQuery);
            $enrolled = (int) $enrolledQuery->count();

            $claimedQuery = DB::table('tbl_subsidy_beneficiaries')
                ->join('farmers', 'farmers.rsbsa_no', '=', 'tbl_subsidy_beneficiaries.farmer_rsbsa_no')
                ->join('tbl_subsidy_programs', 'tbl_subsidy_programs.id', '=', 'tbl_subsidy_beneficiaries.program_id')
                ->whereNull('farmers.deleted_at')
                ->where('tbl_subsidy_beneficiaries.status', 'Claimed')
                ->when($filters['barangay'] ?? null, fn ($query, $barangay) => $query->where('farmers.permanent_brgy', $barangay))
                ->when($filters['commodity'] ?? null, fn ($query, $commodity) => $query->where('tbl_subsidy_programs.target_crop', $commodity));
            SubsidyBeneficiary::applyNotDeleted($claimedQuery);
            $claimed = (int) $claimedQuery->count();
        }

        $percent = $enrolled > 0 ? round(($claimed / $enrolled) * 100, 1) : 0.0;

        return [
            'uptake_percent' => $percent,
            'beneficiaries_claimed' => $claimed,
            'beneficiaries_enrolled' => $enrolled,
        ];
    }

    /**
     * Weekday + top calendar days from distributions.claimed_at (last 90 days).
     *
     * @return array{
     *     window_days: int,
     *     by_weekday: list<array{day: string, count: int}>,
     *     peak_weekday: string|null,
     *     peak_weekday_count: int,
     *     top_days: list<array{date: string, label: string, count: int}>
     * }
     */
    private function peakDisbursementDays(): array
    {
        $empty = [
            'window_days' => 90,
            'by_weekday' => [],
            'peak_weekday' => null,
            'peak_weekday_count' => 0,
            'top_days' => [],
        ];

        if (! Schema::hasTable('distributions') || ! Schema::hasColumn('distributions', 'claimed_at')) {
            return $empty;
        }

        $since = Carbon::now()->subDays(90);
        $dayNames = ['Sunday', 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday'];

        $rows = Distribution::query()
            ->whereNotNull('claimed_at')
            ->where('claimed_at', '>=', $since)
            ->get(['claimed_at']);

        $weekdayCounts = array_fill(0, 7, 0);
        $calendarCounts = [];

        foreach ($rows as $row) {
            $dt = Carbon::parse($row->claimed_at);
            $weekdayCounts[(int) $dt->dayOfWeek]++;
            $key = $dt->toDateString();
            $calendarCounts[$key] = ($calendarCounts[$key] ?? 0) + 1;
        }

        $byWeekday = [];
        $peakWeekday = null;
        $peakCount = 0;
        foreach ($dayNames as $i => $name) {
            $count = $weekdayCounts[$i];
            $byWeekday[] = ['day' => $name, 'count' => $count];
            if ($count > $peakCount) {
                $peakCount = $count;
                $peakWeekday = $name;
            }
        }

        arsort($calendarCounts);
        $topDays = [];
        foreach (array_slice($calendarCounts, 0, 3, true) as $date => $count) {
            $topDays[] = [
                'date' => $date,
                'label' => Carbon::parse($date)->format('M j, Y'),
                'count' => $count,
            ];
        }

        return [
            'window_days' => 90,
            'by_weekday' => $byWeekday,
            'peak_weekday' => $peakCount > 0 ? $peakWeekday : null,
            'peak_weekday_count' => $peakCount,
            'top_days' => $topDays,
        ];
    }
}
