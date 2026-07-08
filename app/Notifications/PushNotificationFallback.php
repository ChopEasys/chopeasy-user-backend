<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;

class PushNotificationFallback extends Notification
{
    use Queueable;

    public function __construct(
        public string $type,
        public array $payload
    ) {
    }

    public function via(object $notifiable): array
    {
        return ['database'];
    }

    public function toArray(object $notifiable): array
    {
        return array_merge($this->payload, [
            'type' => $this->type,
            'push_fallback' => true,
        ]);
    }
}
