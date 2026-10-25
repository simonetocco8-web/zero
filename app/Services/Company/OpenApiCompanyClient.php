<?php

namespace App\Services\Company;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use UnexpectedValueException;

class OpenApiCompanyClient
{
    public function lookupItalianCompany(string $vat): CompanyLookupResult
    {
        $base = rtrim((string) config('services.openapi_company.base_url'), '/');
        if (! is_string(config('services.openapi_company.token')) || trim(config('services.openapi_company.token')) === ''
            || ! in_array($base, ['https://test.company.openapi.com', 'https://company.openapi.com'], true)) {
            return new CompanyLookupResult('unavailable', reason: 'not_configured');
        }
        if (! preg_match('/^[0-9]{11}$/', $vat)) {
            return new CompanyLookupResult('unavailable', reason: 'invalid_vat');
        }
        try {
            // Paid synchronous lookup: no automatic retries or redirects.
            $response = Http::withToken(config('services.openapi_company.token'))->acceptJson()->withoutRedirecting()
                ->connectTimeout(5)->timeout(max(1, min(20, (int) config('services.openapi_company.timeout'))))->get($base.'/IT-start/'.$vat);
        } catch (ConnectionException) {
            return new CompanyLookupResult('unavailable', reason: 'connection_failed');
        }
        if ($response->status() === 204 || ($response->status() === 404 && $response->json('success') === false && $response->json('error') === 302)) {
            return new CompanyLookupResult('not_found');
        }
        if (! $response->successful()) {
            return new CompanyLookupResult('unavailable', reason: match ($response->status()) {
                401, 403 => 'authentication_failed', 402 => 'credit_unavailable', 429 => 'rate_limited',
                400, 406 => 'invalid_request', default => 'service_unavailable',
            });
        }
        $data = $response->json('data');
        if ($response->json('success') !== true || $response->json('error') !== null || ! is_array($data) || ! array_is_list($data) || count($data) !== 1 || ! is_array($data[0])) {
            return new CompanyLookupResult('unavailable', reason: 'invalid_response');
        }
        try {
            return app(CompanyStartMapper::class)->map($data[0], $vat);
        } catch (UnexpectedValueException $failure) {
            Log::warning('Company Start response rejected.', ['lookup_type' => 'IT-start', 'http_status' => $response->status(), 'reason' => $failure->getMessage()]);

            return new CompanyLookupResult('unavailable', reason: $failure->getMessage());
        }
    }
}
