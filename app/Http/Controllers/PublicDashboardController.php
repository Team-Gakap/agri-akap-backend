<?php

namespace App\Http\Controllers;

use App\Models\Distribution;
use App\Models\Farmer;
use App\Models\SubsidyBeneficiary;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Guest-facing aggregated KPIs only — no PII, lists, or mutation endpoints.
 */
class PublicDashboardController extends Controller
{
    public function summary(): JsonResponse
    {
        $gender = $this->farmerGenderAndPwd();
        $subsidy = $this->subsidyUptake();
        $peak = $this->peakDisbursementDays();

        return response()->json([
            'data' => [
                'total_farmers' => Farmer::query()->count(),
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
                'municipality' => 'Echague, Isabela',
                'office' => 'Municipal Agriculture Office',
            ],
        ]);
    }

    /**
     * @return array{male: int, female: int, pwd_male: int, pwd_female: int, rsbsa_verified: int}
     */
    private function farmerGenderAndPwd(): array
    {
        $male = 0;
        $female = 0;
        $pwdMale = 0;
        $pwdFemale = 0;
        $rsbsa = 0;

        if (Schema::hasColumn('farmers', 'sex')) {
            $male = Farmer::query()->where('sex', 'Male')->count();
            $female = Farmer::query()->where('sex', 'Female')->count();
        }

        if (Schema::hasColumn('farmers', 'is_pwd') && Schema::hasColumn('farmers', 'sex')) {
            $pwdMale = Farmer::query()->where('is_pwd', true)->where('sex', 'Male')->count();
            $pwdFemale = Farmer::query()->where('is_pwd', true)->where('sex', 'Female')->count();
        }

        if (Schema::hasColumn('farmers', 'rsbsa_no')) {
            $rsbsa = Farmer::query()
                ->whereNotNull('rsbsa_no')
                ->where('rsbsa_no', '!=', '')
                ->count();
        }

        return [
            'male' => $male,
            'female' => $female,
            'pwd_male' => $pwdMale,
            'pwd_female' => $pwdFemale,
            'rsbsa_verified' => $rsbsa,
        ];
    }

    /**
     * @return array{uptake_percent: float, beneficiaries_claimed: int, beneficiaries_enrolled: int}
     */
    private function subsidyUptake(): array
    {
        $claimed = 0;
        $enrolled = 0;

        if (Schema::hasTable('tbl_subsidy_beneficiaries')) {
            $enrolledQuery = DB::table('tbl_subsidy_beneficiaries');
            SubsidyBeneficiary::applyNotDeleted($enrolledQuery);
            $enrolled = (int) $enrolledQuery->count();

            $claimedQuery = DB::table('tbl_subsidy_beneficiaries')->where('status', 'Claimed');
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
