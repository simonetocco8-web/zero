<?php

namespace App\Services;

use App\Exceptions\EmailDeliveryNotConfigured;
use Illuminate\Support\Arr;
use Illuminate\Support\ConfigurationUrlParser;
use Throwable;

class DeliveringMailerGuard
{
    public function ensure(): void
    {
        try {
            $this->check($this->defaultName());
        } catch (Throwable) {
            // Configuration URLs can contain credentials. Never propagate parser details.
            throw new EmailDeliveryNotConfigured;
        }
    }

    public function diagnostics(): array
    {
        try {
            $name = $this->defaultName();
            $configuration = $this->configuration($name);
        } catch (Throwable) {
            $name = '';
            $configuration = [];
        }
        $safeName = fn ($value) => is_string($value) && preg_match('/\A[a-zA-Z0-9_.-]{1,100}\z/', $value) ? $value : '(non configurato)';
        $host = $configuration['host'] ?? '';
        $from = config('mail.from.address');

        return [
            'Mailer predefinito' => $safeName($name),
            'Transport' => $safeName($configuration['transport'] ?? null),
            'Host' => is_string($host) && preg_match('/\A[a-zA-Z0-9.\-:\[\]]{1,255}\z/', $host) ? $host : '(non configurato)',
            'Porta' => filter_var($configuration['port'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 65535]]) ?: '(non configurata)',
            'From address' => is_string($from) && filter_var($from, FILTER_VALIDATE_EMAIL) ? $from : '(non configurato)',
        ];
    }

    private function defaultName(): string
    {
        return (string) (config('mail.driver') ?: config('mail.default'));
    }

    private function configuration(string $name, bool $nested = false): array
    {
        $legacy = config('mail.driver');
        $configuration = $legacy ? config('mail') : config('mail.mailers.'.$name);
        if (! is_array($configuration)) {
            throw new EmailDeliveryNotConfigured;
        }
        if (isset($configuration['url'])) {
            $configuration = array_merge($configuration, (new ConfigurationUrlParser)->parseConfiguration($configuration));
            $configuration['transport'] = Arr::pull($configuration, 'driver');
        }
        $configuration['transport'] ??= $legacy;
        // Match Laravel MailManager's legacy handling of composite child transports.
        if ($legacy && $nested) {
            $configuration['transport'] = $name;
        }

        return $configuration;
    }

    private function check(string $name, array $visited = []): void
    {
        if (in_array($name, $visited, true)) {
            throw new EmailDeliveryNotConfigured;
        }
        $configuration = $this->configuration($name, $visited !== []);
        $transport = $configuration['transport'] ?? null;
        if (in_array($transport, ['failover', 'roundrobin'], true) && ! empty($configuration['mailers']) && is_array($configuration['mailers'])) {
            foreach ($configuration['mailers'] as $child) {
                $this->check($child, [...$visited, $name]);
            }

            return;
        }
        if (in_array($transport, ['smtp', 'sendmail', 'ses', 'ses-v2', 'mailgun', 'postmark', 'resend'], true)
            || ($transport === 'array' && app()->environment('testing'))) {
            return;
        }

        throw new EmailDeliveryNotConfigured;
    }
}
