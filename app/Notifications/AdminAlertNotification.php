<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;

class AdminAlertNotification extends Notification
{
    use Queueable;

    public function __construct(
        private string $type,
        private string $title,
        private string $message,
        private array $meta = []
    ) {
    }

    public function via($notifiable): array
    {
        return ['database'];
    }

    public function toArray($notifiable): array
    {
        return array_merge([
            'type' => $this->type,
            'audience' => 'admin',
            'title' => $this->title,
            'message' => $this->message,
        ], $this->meta);
    }
}
