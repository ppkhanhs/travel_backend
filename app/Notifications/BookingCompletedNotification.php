<?php

namespace App\Notifications;

use App\Models\Booking;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;

class BookingCompletedNotification extends Notification
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

        return [
            'type' => 'booking_completed',
            'title' => 'Chuyến đi đã hoàn thành',
            'message' => sprintf(
                'Cảm ơn bạn đã đồng hành cùng %s. Hy vọng bạn đã có trải nghiệm tuyệt vời!',
                $tour?->title ?? 'chúng tôi'
            ),
            'booking_id' => (string) $booking->id,
            'tour_id' => $tour ? (string) $tour->id : null,
            'status' => $booking->status,
        ];
    }
}
