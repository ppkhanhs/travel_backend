<?php

namespace App\Console\Commands;

use App\Models\Booking;
use App\Notifications\BookingUpcomingReminderNotification;
use App\Services\NotificationService;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

class SendUpcomingTourReminders extends Command
{
    protected $signature = 'bookings:upcoming-reminders {--days=2 : Number of days ahead to remind}';

    protected $description = 'Send reminders to customers whose tours are about to start';

    public function handle(NotificationService $notifications): int
    {
        $days = max(1, (int) $this->option('days'));
        $from = Carbon::today();
        $to = Carbon::today()->addDays($days);

        $bookings = Booking::query()
            ->with(['user', 'tourSchedule.tour'])
            ->where('status', 'confirmed')
            ->whereNull('reminder_sent_at')
            ->whereHas('tourSchedule', function ($query) use ($from, $to) {
                $query->whereBetween('start_date', [$from->toDateString(), $to->toDateString()]);
            })
            ->get();

        $sent = 0;

        foreach ($bookings as $booking) {
            if (!$booking->user) {
                continue;
            }

            $notifications->notify($booking->user, new BookingUpcomingReminderNotification($booking));
            $booking->reminder_sent_at = now();
            $booking->save();
            $sent++;
        }

        $this->info("Sent {$sent} reminders.");

        return Command::SUCCESS;
    }
}
