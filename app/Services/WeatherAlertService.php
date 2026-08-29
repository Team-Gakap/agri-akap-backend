<?php

namespace App\Services;

use App\Models\Farmer;
use App\Models\SmsBroadcast;
use App\Models\WeatherCache;
use Carbon\Carbon;

class WeatherAlertService
{
    public const PRECIP_THRESHOLD = 80;

    public const HEAT_THRESHOLD = 38.0;

    /** km/h — above this, pesticide spray drift risk is high. */
    public const WIND_THRESHOLD = 15.0;

    public function __construct(private SmsService $sms)
    {
    }

    /**
     * Evaluate tomorrow's forecast per barangay and SMS only affected farmers.
     *
     * @return array{
     *   sent:bool,
     *   skipped:?string,
     *   alerts_sent:int,
     *   recipient_count:int,
     *   mocked:bool,
     *   details:array<int, array<string,mixed>>
     * }
     */
    public function evaluateAndSend(bool $force = false, string $triggerType = SmsBroadcast::TRIGGER_AUTOMATED_WEATHER): array
    {
        $evaluations = $this->evaluateTomorrowByBarangay();

        if ($evaluations === []) {
            return [
                'sent' => false,
                'skipped' => 'No barangay weather thresholds met for tomorrow.',
                'alert_type' => null,
                'message' => null,
                'alerts_sent' => 0,
                'recipient_count' => 0,
                'mocked' => false,
                'details' => [],
                'broadcast' => null,
            ];
        }

        $details = [];
        $totalRecipients = 0;
        $alertsSent = 0;
        $anyMocked = false;
        $anySent = false;

        foreach ($evaluations as $evaluation) {
            $barangay = $evaluation['barangay'];

            if (
                ! $force
                && $triggerType === SmsBroadcast::TRIGGER_AUTOMATED_WEATHER
                && $this->alreadySentTodayForBarangay($barangay, $evaluation['alert_type'])
            ) {
                $details[] = [
                    'barangay' => $barangay,
                    'skipped' => 'Already sent today (anti-spam).',
                    'alert_type' => $evaluation['alert_type'],
                ];
                continue;
            }

            $numbers = $this->farmerMobileNumbersForBarangay($barangay);
            if ($numbers === []) {
                $details[] = [
                    'barangay' => $barangay,
                    'skipped' => 'No farmer mobile numbers in this barangay.',
                    'alert_type' => $evaluation['alert_type'],
                ];
                continue;
            }

            $send = $this->dispatchSms($numbers, $evaluation['message']);
            $anyMocked = $anyMocked || $send['mocked'];
            $status = $send['success'] ? SmsBroadcast::STATUS_SENT : SmsBroadcast::STATUS_FAILED;

            $broadcast = SmsBroadcast::create([
                'target_barangay' => $barangay,
                'target_commodity' => 'All',
                'message_body' => $evaluation['message'],
                'trigger_type' => $triggerType,
                'alert_type' => $evaluation['alert_type'],
                'recipient_count' => count($numbers),
                'status' => $status,
            ]);

            if ($send['success']) {
                $anySent = true;
                $alertsSent++;
                $totalRecipients += count($numbers);
            }

            $details[] = [
                'barangay' => $barangay,
                'alert_type' => $evaluation['alert_type'],
                'message' => $evaluation['message'],
                'recipient_count' => count($numbers),
                'mocked' => $send['mocked'],
                'success' => $send['success'],
                'broadcast_id' => $broadcast->id,
            ];
        }

        return [
            'sent' => $anySent,
            'skipped' => $anySent ? null : 'No advisories were dispatched.',
            'alert_type' => $alertsSent > 0 ? "{$alertsSent} barangay advisory(ies)" : null,
            'message' => null,
            'alerts_sent' => $alertsSent,
            'recipient_count' => $totalRecipients,
            'mocked' => $anyMocked,
            'details' => $details,
            'broadcast' => null,
        ];
    }

    /**
     * Suggested hyper-local advisories for the Admin Broadcast Center.
     *
     * @return array{has_advisory:bool, advisories:array<int,array<string,mixed>>, reason:?string, already_sent_today:bool}
     */
    public function suggestedAdvisories(): array
    {
        $evaluations = $this->evaluateTomorrowByBarangay();
        $already = false;

        $advisories = array_map(function (array $e) use (&$already) {
            $sent = $this->alreadySentTodayForBarangay($e['barangay'], $e['alert_type']);
            $already = $already || $sent;

            return [
                'barangay' => $e['barangay'],
                'alert_type' => $e['alert_type'],
                'message' => $e['message'],
                'forecast' => $e['forecast'],
                'already_sent_today' => $sent,
            ];
        }, $evaluations);

        return [
            'has_advisory' => $advisories !== [],
            'advisories' => $advisories,
            // Back-compat for older UI that expects a single message.
            'alert_type' => $advisories[0]['alert_type'] ?? null,
            'message' => $advisories[0]['message'] ?? null,
            'forecast' => $advisories[0]['forecast'] ?? null,
            'already_sent_today' => $already,
            'reason' => $advisories === []
                ? 'No barangay exceeds rain ≥ 80%, max temp ≥ 38°C, or wind > 15 km/h tomorrow.'
                : null,
        ];
    }

    /**
     * @return list<array{barangay:string, alert_type:string, message:string, forecast:array}>
     */
    public function evaluateTomorrowByBarangay(): array
    {
        $tomorrow = Carbon::now(WeatherService::TIMEZONE)->addDay()->toDateString();

        $rows = WeatherCache::query()
            ->whereDate('forecast_date', $tomorrow)
            ->orderBy('barangay_name')
            ->get();

        $out = [];

        foreach ($rows as $row) {
            $alerts = [];

            if ((int) ($row->precipitation_probability ?? 0) >= self::PRECIP_THRESHOLD) {
                $alerts[] = 'Heavy Rain';
            }
            if ((float) ($row->temperature_max ?? 0) >= self::HEAT_THRESHOLD) {
                $alerts[] = 'Extreme Heat';
            }
            if ((float) ($row->wind_speed_10m ?? 0) > self::WIND_THRESHOLD) {
                $alerts[] = 'High Wind / Spray Drift Risk';
            }

            if ($alerts === []) {
                continue;
            }

            $alertType = implode(' and ', $alerts);
            $barangay = $row->barangay_name;

            $message = $this->draftMessage($barangay, $alerts);

            $out[] = [
                'barangay' => $barangay,
                'alert_type' => $alertType,
                'message' => $message,
                'forecast' => $this->forecastSummary($row),
            ];
        }

        return $out;
    }

    /**
     * @param  list<string>  $alerts
     */
    protected function draftMessage(string $barangay, array $alerts): string
    {
        $hasWind = in_array('High Wind / Spray Drift Risk', $alerts, true);
        $weatherAlerts = array_values(array_filter(
            $alerts,
            fn ($a) => $a !== 'High Wind / Spray Drift Risk'
        ));

        $parts = [];
        if ($weatherAlerts !== []) {
            $parts[] = implode(' and ', $weatherAlerts).' is expected tomorrow in Brgy. '.$barangay;
        }
        if ($hasWind) {
            $parts[] = 'Strong winds (>15 km/h) expected — avoid pesticide spraying due to spray drift risk';
        }

        $body = implode('. ', $parts);

        return "MAO Echague Advisory ({$barangay}): {$body}. Please take necessary precautions for your crops and livestock. Stay safe!";
    }

    protected function alreadySentTodayForBarangay(string $barangay, string $alertType): bool
    {
        $today = Carbon::now(WeatherService::TIMEZONE)->toDateString();

        return SmsBroadcast::query()
            ->where('trigger_type', SmsBroadcast::TRIGGER_AUTOMATED_WEATHER)
            ->where('target_barangay', $barangay)
            ->where('alert_type', $alertType)
            ->whereDate('created_at', $today)
            ->where('status', SmsBroadcast::STATUS_SENT)
            ->exists();
    }

    /**
     * Farmers are geo-tagged via permanent_brgy (barangay of residence / farm).
     *
     * @return array<int, string>
     */
    protected function farmerMobileNumbersForBarangay(string $barangay): array
    {
        return Farmer::query()
            ->where('permanent_brgy', $barangay)
            ->whereNotNull('mobile_number')
            ->where('mobile_number', '!=', '')
            ->pluck('mobile_number')
            ->map(fn ($n) => preg_replace('/\s+/', '', (string) $n))
            ->filter()
            ->unique()
            ->values()
            ->all();
    }

    /**
     * @param  array<int, string>  $numbers
     * @return array{success:bool, mocked:bool, raw:mixed}
     */
    protected function dispatchSms(array $numbers, string $message): array
    {
        $result = $this->sms->sendBulk($numbers, $message);
        $provider = (string) ($result['provider'] ?? '');

        return [
            'success' => (bool) ($result['success'] ?? false),
            'mocked' => str_contains($provider, 'mock'),
            'raw' => $result['raw'] ?? null,
        ];
    }

    protected function forecastSummary(WeatherCache $row): array
    {
        return [
            'barangay_name' => $row->barangay_name,
            'forecast_date' => $row->forecast_date->toDateString(),
            'temperature_min' => $row->temperature_min !== null ? (float) $row->temperature_min : null,
            'temperature_max' => $row->temperature_max !== null ? (float) $row->temperature_max : null,
            'precipitation_probability' => $row->precipitation_probability,
            'evapotranspiration' => $row->evapotranspiration !== null ? (float) $row->evapotranspiration : null,
            'soil_moisture_28cm' => $row->soil_moisture_28cm !== null ? (float) $row->soil_moisture_28cm : null,
            'wind_speed_10m' => $row->wind_speed_10m !== null ? (float) $row->wind_speed_10m : null,
            'weather_code' => $row->weather_code,
        ];
    }
}
