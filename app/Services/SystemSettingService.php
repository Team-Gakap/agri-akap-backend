<?php

namespace App\Services;

use App\Models\SystemSetting;
use Illuminate\Support\Facades\Cache;

class SystemSettingService
{
    public const SMS_PROVIDER_KEY = 'sms.provider';

    public const PROVIDER_IPROG = 'iprog';

    public const PROVIDER_SEMAPHORE = 'semaphore';

    /** @var list<string> */
    public const SMS_PROVIDERS = [self::PROVIDER_IPROG, self::PROVIDER_SEMAPHORE];

    public function get(string $key): ?string
    {
        $cached = Cache::rememberForever($this->cacheKey($key), function () use ($key) {
            return SystemSetting::query()->where('key', $key)->value('value');
        });

        if ($cached === null || $cached === '') {
            return null;
        }

        return (string) $cached;
    }

    public function set(string $key, string $value): void
    {
        SystemSetting::query()->updateOrCreate(
            ['key' => $key],
            ['value' => $value],
        );

        Cache::forget($this->cacheKey($key));
    }

    public function smsProvider(): string
    {
        $stored = $this->get(self::SMS_PROVIDER_KEY);
        if ($this->isValidProvider($stored)) {
            return $stored;
        }

        $env = (string) config('services.sms.provider', self::PROVIDER_IPROG);

        return $this->isValidProvider($env) ? $env : self::PROVIDER_IPROG;
    }

    public function smsProviderSource(): string
    {
        $stored = $this->get(self::SMS_PROVIDER_KEY);

        return $this->isValidProvider($stored) ? 'database' : 'env';
    }

    public function isValidProvider(?string $provider): bool
    {
        return in_array($provider, self::SMS_PROVIDERS, true);
    }

    private function cacheKey(string $key): string
    {
        return 'system_setting.'.$key;
    }
}
