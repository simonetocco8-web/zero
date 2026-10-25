<?php

namespace App\Services\BankVerification;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Http;

class OpenApiTrustClient
{
    public function configured(): bool
    {
        return is_string(config('services.openapi_trust.token')) && trim(config('services.openapi_trust.token')) !== ''
            && in_array(rtrim((string) config('services.openapi_trust.base_url'), '/'), ['https://test.trust.openapi.com', 'https://trust.openapi.com'], true);
    }

    public function startIbanAdvanced(string $iban): array
    {
        return $this->request('POST', '/iban-advanced/'.rawurlencode($iban), $iban);
    }

    public function getIbanAdvanced(string $id, string $iban): array
    {
        return $this->request('GET', '/iban-advanced/'.rawurlencode($id), $iban, expectedId: $id);
    }

    public function startVop(string $iban, string $name): array
    {
        return $this->request('POST', '/iban-vop', $iban, $name);
    }

    public function getVop(string $id, string $iban, string $name): array
    {
        return $this->request('GET', '/iban-vop/'.rawurlencode($id), $iban, $name, $id);
    }

    private function request(string $method, string $path, string $iban, ?string $name = null, ?string $expectedId = null): array
    {
        if (! $this->configured()) {
            throw new TrustFailure('not_configured');
        }
        try {
            // No HTTP retry: even a timed-out POST may have incurred a charge.
            $response = Http::withToken(config('services.openapi_trust.token'))->acceptJson()->asJson()
                ->connectTimeout(5)->timeout(max(1, min(20, (int) config('services.openapi_trust.timeout'))))
                ->withoutRedirecting()->send($method, rtrim(config('services.openapi_trust.base_url'), '/').$path,
                    $method === 'POST' ? ['json' => $name === null ? (object) [] : ['iban' => $iban, 'name' => $name]] : []);
        } catch (ConnectionException) {
            throw new TrustFailure('connection_failed', true);
        }
        if (! $response->successful()) {
            $code = match ($response->status()) {
                401, 403 => 'authentication_failed',
                400 => 'invalid_request',
                404 => 'request_not_found',
                406 => 'functional_error',
                429 => 'rate_limited',
                default => $response->serverError() ? 'service_unavailable' : 'http_error',
            };
            throw new TrustFailure($code, $response->status() === 429 || $response->serverError());
        }
        $data = $response->json('data');
        if ($response->json('success') !== true || ! is_array($data) || ! is_string($data['id'] ?? null)
            || ! preg_match('/^[a-zA-Z0-9_-]{1,128}$/', $data['id']) || ! in_array($data['state'] ?? null, ['NEW', 'WAIT', 'DONE'], true)
            || ($expectedId !== null && $expectedId !== $data['id'])) {
            throw new TrustFailure('invalid_response');
        }
        try {
            if (isset($data['iban']) && $data['iban'] !== $iban) {
                throw new TrustFailure('account_mismatch');
            }
            $result = ['id' => $data['id'], 'state' => $data['state']];
            if ($data['state'] !== 'DONE') {
                return $result;
            }
            if (($data['iban'] ?? null) !== $iban) {
                throw new TrustFailure('account_mismatch');
            }
            if ($name === null) {
                // OAS 3.0.3: all six structured validation flags must be present and boolean.
                $flags = [];
                foreach (['chars', 'account', 'iban', 'structure', 'length', 'countrySupport'] as $check) {
                    $flag = data_get($data, 'validations.'.$check.'.valid');
                    if (! is_bool($flag)) {
                        throw new TrustFailure('invalid_response');
                    }
                    $flags[] = $flag;
                }
                $result['valid'] = ! in_array(false, $flags, true) && empty($data['errors']);
                $result['bank_data'] = $this->strings($data['bankData'] ?? [], ['bank', 'branch', 'bic', 'address', 'city', 'state', 'zipCode', 'country', 'countryCode']);
                $result['sepa_data'] = [];
                foreach (['creditTransfer', 'directDebit', 'cor1', 'b2b', 'cardClearing', 'creditInstantTransferRT1', 'creditInstantTransferTIPS'] as $key) {
                    if (isset($data['sepaData'][$key])) {
                        if (! is_bool($data['sepaData'][$key])) {
                            throw new TrustFailure('invalid_response');
                        }
                        $result['sepa_data'][$key] = $data['sepaData'][$key];
                    }
                }
            } else {
                if (isset($data['name']) && $data['name'] !== $name) {
                    throw new TrustFailure('payee_mismatch');
                }
                $outcome = $data['vopResult'] ?? 'UNKNOWN';
                if (! in_array($outcome, ['MATCH', 'CLOSE_MATCH', 'NO_MATCH', 'NOT_APPLICABLE', 'ERROR', 'UNKNOWN'], true)) {
                    throw new TrustFailure('invalid_response');
                }
                if (! empty($data['errors']) && ! in_array($outcome, ['ERROR', 'UNKNOWN'], true)) {
                    throw new TrustFailure('invalid_response');
                }
                $details = $data['details'] ?? [];
                if (! is_array($details) || (isset($details['iban']) && $details['iban'] !== $iban)) {
                    throw new TrustFailure('account_mismatch');
                }
                $type = $details['matchType'] ?? null;
                if ($type !== null && $type !== (['MATCH' => 'MTCH', 'CLOSE_MATCH' => 'CMTC', 'NO_MATCH' => 'NMTC', 'NOT_APPLICABLE' => 'NOAP'][$outcome] ?? null)) {
                    throw new TrustFailure('invalid_response');
                }
                $result['vop_result'] = $outcome;
                $result['match_type'] = $type;
                $result['owner_name'] = in_array($outcome, ['MATCH', 'CLOSE_MATCH', 'NO_MATCH'], true)
                    ? ($this->strings($details, ['ownerName'])['ownerName'] ?? null) : null;
            }

            return $result;
        } catch (TrustFailure $failure) {
            throw new TrustFailure($failure->reason, $failure->retryable, $data['id'], $data['state']);
        }
    }

    private function strings(mixed $source, array $keys): array
    {
        if (! is_array($source)) {
            throw new TrustFailure('invalid_response');
        }
        $values = [];
        foreach (Arr::only($source, $keys) as $key => $value) {
            if ($value === null) {
                continue;
            }
            if (! is_string($value) || mb_strlen($value) > 255 || preg_match('/[\x00-\x1F\x7F]/u', $value)) {
                throw new TrustFailure('invalid_response');
            }
            if (trim($value) !== '') {
                $values[$key] = trim($value);
            }
        }

        return $values;
    }
}
