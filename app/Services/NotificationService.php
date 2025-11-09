<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Notifications\Notification;

class NotificationService
{
    public function notify(?User $user, Notification $notification): void
    {
        if (!$user || $user->notifications_enabled === false) {
            return;
        }

        $user->notify($notification);
    }
}

