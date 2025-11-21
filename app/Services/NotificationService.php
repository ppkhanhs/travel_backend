<?php

namespace App\Services;

use App\Models\Booking;
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

    public function notifyAdmins(Notification $notification): void
    {
        User::query()
            ->where('role', 'admin')
            ->where(function ($query) {
                $query->whereNull('notifications_enabled')->orWhere('notifications_enabled', true);
            })
            ->cursor()
            ->each(fn (User $admin) => $this->notify($admin, $notification));
    }

    public function notifyPartnerByBooking(?Booking $booking, Notification $notification): void
    {
        $partnerUser = $booking?->tourSchedule?->tour?->partner?->user;
        if ($partnerUser) {
            $this->notify($partnerUser, $notification);
        }
    }
}
