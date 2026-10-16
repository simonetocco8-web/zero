<?php

namespace App\Notifications;

use App\Mail\MarketplaceNoticeMail;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Notification;

class MarketplaceNotice extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(public array $data, string $uuid)
    {
        $this->id = $uuid;
        $this->onConnection(config('marketplace.notifications.queue_connection'));
        $this->onQueue(config('marketplace.notifications.queue'));
        $this->afterCommit();
    }

    public function via(object $notifiable): array
    {
        return config('marketplace.notifications.channels');
    }

    public function toDatabase(object $notifiable): array
    {
        return $this->data;
    }

    public function toMail(object $notifiable): MarketplaceNoticeMail
    {
        return (new MarketplaceNoticeMail($this->data))->to($notifiable->email);
    }
}
