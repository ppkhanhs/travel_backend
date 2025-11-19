<?php

namespace App\Notifications;

use App\Models\Booking;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;

class BookingUpcomingReminderNotification extends Notification
{
    use Queueable;

    public function __construct(private Booking $booking)
    {
    }

    public function via($notifiable): array
    {
        return ['database'];
    }

    public function toArray($notifiable): array
    {
        $booking = $this->booking;
        $tour = $booking->tourSchedule?->tour;
        $start = $booking->tourSchedule?->start_date;
        $startDate = $start instanceof \Illuminate\Support\Carbon ? $start->toDateString() : ($start ? (string) $start : null);

        return [
            'type' => 'booking_upcoming',
            'title' => 'Tour sắp khởi hành',
            'message' => sprintf(
                'Tour %s sẽ khởi hành vào %s. Vui lòng chuẩn bị giấy tờ và hành lý cần thiết.',
                $tour?->title ?? '',
                $startDate ?? 'ngày tới'
            ),
            'booking_id' => (string) $booking->id,
            'tour_id' => $tour ? (string) $tour->id : null,
            'status' => $booking->status,
            'start_date' => $startDate,
        ];
    }
}
