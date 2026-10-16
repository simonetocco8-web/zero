<?php

namespace App\Notifications;

use Illuminate\Notifications\Notification;

class LifecycleChanged extends Notification
{
    public function __construct(public int $eventId, public string $status) {}

    public function via(object $notifiable): array
    {
        return ['database'];
    }

    public function toArray(object $notifiable): array
    {
        return ['lifecycle_event_id' => $this->eventId, 'status' => $this->status];
    }
}
