<?php

namespace App\Jobs;

use App\Models\MarketplaceNoticeOutbox;
use App\Models\User;
use App\Notifications\MarketplaceNotice;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;

class DeliverMarketplaceNotice implements ShouldQueue
{
    use Queueable;

    public int $tries = 5;

    public int $timeout = 60;

    public function __construct(public int $outboxId) {}

    public function backoff(): array
    {
        return [10, 60, 180, 600];
    }

    public function handle(): void
    {
        $claimed = DB::transaction(function () {
            $n = MarketplaceNoticeOutbox::lockForUpdate()->findOrFail($this->outboxId);
            if ($n->attempts >= 5 || $n->completed_at || ($n->processing_at && $n->processing_at > now()->subMinutes(2))) {
                return false;
            }
            $n->update(['processing_at' => now(), 'attempts' => $n->attempts + 1]);

            return true;
        }, 5);
        if (! $claimed) {
            return;
        }
        try {
            $n = MarketplaceNoticeOutbox::findOrFail($this->outboxId);
            $u = User::findOrFail($n->user_id);
            $notice = new MarketplaceNotice($n->data, $n->notification_uuid);
            if ($n->database_enabled && ! $n->database_delivered_at) {
                DB::transaction(function () use ($n, $u, $notice) {
                    if (! $u->notifications()->whereKey($n->notification_uuid)->exists()) {
                        Notification::sendNow($u, $notice, ['database']);
                    }$n->update(['database_delivered_at' => now()]);
                });
            }
            if ($n->email_enabled && ! $n->email_delivered_at && $u->marketplace_email_notifications) {
                Mail::send($notice->toMail($u));
                $n->update(['email_delivered_at' => now()]);
            }
            $n->update(['completed_at' => now(), 'processing_at' => null]);
        } catch (\Throwable $e) {
            MarketplaceNoticeOutbox::whereKey($this->outboxId)->update(['processing_at' => null]);
            throw $e;
        }
    }
}
