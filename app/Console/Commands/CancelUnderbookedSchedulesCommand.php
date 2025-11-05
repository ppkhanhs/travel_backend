<?php

namespace App\Console\Commands;

use App\Mail\UnderbookedTourCancellationMail;
use App\Models\Booking;
use App\Models\TourSchedule;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

class CancelUnderbookedSchedulesCommand extends Command
{
    protected $signature = 'tours:cancel-underbooked {--days=3 : Number of days before departure to check}';

    protected $description = 'Cancel tour schedules that do not meet minimum participant requirements and notify customers.';

    public function handle(): int
    {
        $days = max(1, (int) $this->option('days'));
        $now = now();
        $endDate = $now->copy()->addDays($days)->endOfDay();

        $schedules = TourSchedule::query()
            ->with([
                'tour.partner',
                'bookings' => function ($query) {
                    $query->whereIn('status', ['pending', 'confirmed'])
                        ->with(['user', 'payments']);
                },
            ])
            ->whereNotNull('min_participants')
            ->where('min_participants', '>', 0)
            ->whereDate('start_date', '>', $now->toDateString())
            ->whereDate('start_date', '<=', $endDate->toDateString())
            ->whereHas('bookings', function ($query) {
                $query->whereIn('status', ['pending', 'confirmed']);
            })
            ->get();

        $cancelledSchedules = 0;
        $affectedBookings = 0;

        foreach ($schedules as $schedule) {
            $activeBookings = $schedule->bookings;
            $participantCount = $activeBookings->sum(function (Booking $booking) {
                return (int) $booking->total_adults + (int) $booking->total_children;
            });

            if ($participantCount >= (int) $schedule->min_participants) {
                continue;
            }

            $result = DB::transaction(function () use ($schedule, $activeBookings) {
                $lockedSchedule = TourSchedule::query()
                    ->where('id', $schedule->id)
                    ->lockForUpdate()
                    ->first();

                if (!$lockedSchedule) {
                    return [0, collect()];
                }

                $currentBookings = Booking::query()
                    ->where('tour_schedule_id', $lockedSchedule->id)
                    ->whereIn('status', ['pending', 'confirmed'])
                    ->with(['payments', 'user'])
                    ->get();

                $releasedSeats = 0;
                $notifiedBookings = collect();

                foreach ($currentBookings as $booking) {
                    $releasedSeats += (int) $booking->total_adults + (int) $booking->total_children;
                    $this->cancelBookingWithRefund($booking);
                    $notifiedBookings->push($booking);
                }

                if ($releasedSeats > 0) {
                    $lockedSchedule->seats_available = min(
                        (int) $lockedSchedule->seats_total,
                        (int) $lockedSchedule->seats_available + $releasedSeats
                    );
                    $lockedSchedule->save();
                }

                return [$currentBookings->count(), $notifiedBookings];
            });

            [$cancelledCount, $bookingsCollection] = $result;

            if ($cancelledCount === 0) {
                continue;
            }

            $cancelledSchedules++;
            $affectedBookings += $cancelledCount;

            $alternatives = $this->fetchAlternativeSchedules($schedule);

            foreach ($bookingsCollection as $booking) {
                $recipient = $booking->contact_email ?: optional($booking->user)->email;

                if (!$recipient) {
                    continue;
                }

                Mail::to($recipient)->send(
                    new UnderbookedTourCancellationMail($booking->fresh(['tourSchedule.tour']), $alternatives)
                );
            }

            Log::info('[Tours] Cancelled schedule due to insufficient participants', [
                'schedule_id' => $schedule->id,
                'tour_id' => $schedule->tour_id,
                'participant_count' => $participantCount,
                'min_required' => $schedule->min_participants,
                'bookings_cancelled' => $cancelledCount,
            ]);
        }

        $this->info(sprintf(
            'Cancelled %d schedule(s); notified %d booking(s).',
            $cancelledSchedules,
            $affectedBookings
        ));

        return Command::SUCCESS;
    }

    private function cancelBookingWithRefund(Booking $booking): void
    {
        $booking->status = 'cancelled';
        $booking->payment_status = 'refunded';
        $booking->notes = trim(($booking->notes ? $booking->notes . PHP_EOL : '') . '[System] Cancelled due to insufficient participants.');
        $booking->save();

        foreach ($booking->payments as $payment) {
            $amount = (float) ($payment->total_amount ?? $payment->amount ?? 0);
            $payment->status = 'refunded';
            $payment->refund_amount = $amount;
            $payment->save();
        }
    }

    private function fetchAlternativeSchedules(TourSchedule $schedule): Collection
    {
        return TourSchedule::query()
            ->where('tour_id', $schedule->tour_id)
            ->where('id', '!=', $schedule->id)
            ->whereDate('start_date', '>', $schedule->start_date)
            ->orderBy('start_date')
            ->limit(5)
            ->get(['id', 'start_date', 'end_date', 'seats_available', 'min_participants']);
    }
}
