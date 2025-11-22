<?php

namespace App\Notifications;

use App\Models\Booking;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;

class BookingConfirmedNotification extends Notification
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
            'type' => 'booking_confirmed',
            'audience' => 'customer',
            'title' => 'Tour đã được xác nhận',
            'message' => sprintf(
                'Đối tác đã xác nhận đơn #%s cho tour %s. Hãy chuẩn bị cho chuyến đi của bạn!',
                $booking->id,
                $tour?->title ?? ''
            ),
            'booking_id' => (string) $booking->id,
            'tour_id' => $tour ? (string) $tour->id : null,
            'status' => $booking->status,
            'start_date' => $startDate,
        ];
    }
}
