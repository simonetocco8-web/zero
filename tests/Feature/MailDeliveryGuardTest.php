<?php

namespace Tests\Feature;

use App\Exceptions\EmailDeliveryNotConfigured;
use App\Services\DeliveringMailerGuard;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Mail;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\Mailer\Transport\Smtp\EsmtpTransport;
use Tests\TestCase;

class MailDeliveryGuardTest extends TestCase
{
    public static function deliveringTransports(): array
    {
        return array_map(fn ($transport) => [$transport], ['smtp', 'sendmail', 'ses', 'ses-v2', 'mailgun', 'postmark', 'resend']);
    }

    #[DataProvider('deliveringTransports')]
    public function test_real_transports_are_accepted_without_connecting(string $transport): void
    {
        config(['mail.driver' => null, 'mail.default' => 'delivery', 'mail.mailers.delivery' => ['transport' => $transport]]);
        app(DeliveringMailerGuard::class)->ensure();
        $this->assertSame($transport, app(DeliveringMailerGuard::class)->diagnostics()['Transport']);
    }

    public function test_smtp_with_null_url_matches_laravels_real_transport_and_legacy_is_supported(): void
    {
        config(['mail.default' => 'smtp', 'mail.mailers.smtp.url' => null]);
        app(DeliveringMailerGuard::class)->ensure();
        $this->assertInstanceOf(EsmtpTransport::class, Mail::mailer('smtp')->getSymfonyTransport());
        config(['mail.driver' => 'smtp', 'mail.host' => 'smtp.example.test', 'mail.port' => 587]);
        app(DeliveringMailerGuard::class)->ensure();
        $this->assertSame('smtp', app(DeliveringMailerGuard::class)->diagnostics()['Transport']);
    }

    public function test_array_is_allowed_only_in_testing(): void
    {
        config(['mail.default' => 'array']);
        app(DeliveringMailerGuard::class)->ensure();
        $this->app->instance('env', 'production');
        $this->expectException(EmailDeliveryNotConfigured::class);
        app(DeliveringMailerGuard::class)->ensure();
    }

    public static function invalidConfigurations(): array
    {
        return [
            'log' => [['mail.default' => 'log']],
            'unknown' => [['mail.default' => 'missing']],
            'null' => [['mail.default' => null]],
            'url override' => [['mail.default' => 'smtp', 'mail.mailers.smtp.url' => 'log://localhost']],
            'log failover' => [['mail.default' => 'failover']],
            'cycle' => [['mail.default' => 'loop', 'mail.mailers.loop' => ['transport' => 'failover', 'mailers' => ['loop']]]],
        ];
    }

    #[DataProvider('invalidConfigurations')]
    public function test_non_delivering_and_cyclic_transports_are_rejected(array $configuration): void
    {
        config($configuration);
        $this->expectException(EmailDeliveryNotConfigured::class);
        app(DeliveringMailerGuard::class)->ensure();
    }

    public function test_only_delivering_failover_children_are_accepted(): void
    {
        config(['mail.default' => 'failover', 'mail.mailers.failover.mailers' => ['smtp', 'sendmail']]);
        app(DeliveringMailerGuard::class)->ensure();
        $this->assertSame('failover', app(DeliveringMailerGuard::class)->diagnostics()['Transport']);
    }

    public function test_diagnostics_show_only_the_five_safe_fields_even_with_url_credentials(): void
    {
        config(['mail.default' => 'smtp', 'mail.mailers.smtp.url' => 'smtp://never-print-user:never-print-password@smtp.example.test:587', 'mail.from.address' => 'sender@example.test']);
        $this->assertSame(0, Artisan::call('mail:diagnose'));
        $output = Artisan::output();
        $this->assertSame(5, count(explode("\n", trim($output))));
        foreach (['Mailer predefinito: smtp', 'Transport: smtp', 'Host: smtp.example.test', 'Porta: 587', 'From address: sender@example.test'] as $line) {
            $this->assertStringContainsString($line, $output);
        }
        $this->assertStringNotContainsString('never-print', $output);
        $this->assertStringNotContainsString('smtp://', $output);
        app(DeliveringMailerGuard::class)->ensure();
    }
}
