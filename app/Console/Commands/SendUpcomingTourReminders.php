<?php

namespace App\Console\Commands;

use App\Models\Booking;
use App\Notifications\BookingUpcomingReminderNotification;
use App\Services\NotificationService;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

class SendUpcomingTourReminders extends Command
{
    protected $signature = 'bookings:upcoming-reminders';

    protected $description = 'Send reminders to customers whose tours are about to start (2 và 5 ngày trước)';

    public function handle(NotificationService $notifications): int
    {
        $windows = [
            5 => 'reminder_5d_sent_at',
            2 => 'reminder_2d_sent_at',
        ];

        $totalSent = 0;

        foreach ($windows as $days => $column) {
            $targetDate = Carbon::today()->addDays($days)->toDateString();

            $bookings = Booking::query()
                ->with(['user', 'tourSchedule.tour'])
                ->where('status', 'confirmed')
                ->whereNull($column)
                ->whereHas('tourSchedule', function ($query) use ($targetDate) {
                    $query->whereDate('start_date', $targetDate);
                })
                ->get();

            foreach ($bookings as $booking) {
                if (!$booking->user) {
                    continue;
                }

                $notifications->notify($booking->user, new BookingUpcomingReminderNotification($booking, $days));
                $booking->{$column} = now();
                $booking->save();
                $totalSent++;
            }
        }

        $this->info("Sent {$totalSent} reminders.");

        return Command::SUCCESS;
    }
}
