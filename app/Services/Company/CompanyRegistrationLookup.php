<?php

namespace App\Services\Company;

use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Validator;

class CompanyRegistrationLookup
{
    public const SESSION_KEY = 'registration.company_lookup';

    public const CONTEXT_SECONDS = 1800;

    public function lookup(string $vat): array
    {
        Validator::make(['vat_number' => $vat], ['vat_number' => ['required', 'regex:/^[0-9]{11}$/']])->validate();
        $key = 'company-start:v1:'.hash('sha256', config('services.openapi_company.base_url').'|'.$vat);
        try {
            return Cache::lock($key.':lock', 30)->block(1, function () use ($key, $vat) {
                if ($cached = Cache::get($key)) {
                    return $cached;
                }
                $result = app(OpenApiCompanyClient::class)->lookupItalianCompany($vat)->toArray();
                $ttl = match ($result['status']) {
                    'found' => 21600, 'not_found' => 3600, default => 20
                };
                Cache::put($key, $result, $ttl);

                return $result;
            });
        } catch (LockTimeoutException) {
            return (new CompanyLookupResult('unavailable', reason: 'busy'))->toArray();
        }
    }

    public static function validContext(mixed $context): bool
    {
        return is_array($context) && is_string($context['vat_number'] ?? null) && preg_match('/^[0-9]{11}$/', $context['vat_number'])
            && is_int($context['looked_up_at'] ?? null) && $context['looked_up_at'] <= now()->timestamp
            && $context['looked_up_at'] > now()->timestamp - self::CONTEXT_SECONDS
            && in_array($context['status'] ?? null, ['found', 'not_found', 'unavailable'], true);
    }

    public static function normalize(string $value): string
    {
        $value = strtoupper(preg_replace('/\s+/u', '', $value));

        return str_starts_with($value, 'IT') ? substr($value, 2) : $value;
    }
}
