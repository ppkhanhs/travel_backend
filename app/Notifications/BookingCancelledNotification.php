<?php

namespace App\Notifications;

use App\Models\Booking;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;

class BookingCancelledNotification extends Notification
{
    use Queueable;

    public function __construct(private Booking $booking, private bool $approved = false)
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

        $message = $this->approved
            ? sprintf('Yêu cầu hủy tour #%s đã được chấp nhận.', $booking->id)
            : sprintf('Bạn đã hủy đơn #%s.', $booking->id);

        return [
            'type' => 'booking_cancelled',
            'title' => 'Hủy tour',
            'message' => $message,
            'booking_id' => (string) $booking->id,
            'tour_id' => $tour ? (string) $tour->id : null,
            'status' => $booking->status,
        ];
    }
}
