<?php

namespace App\Notifications;

use App\Models\Booking;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;

class AdminBookingNotification extends Notification
{
    use Queueable;

    public function __construct(private Booking $booking, private string $event)
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

        $messages = [
            'booking_created' => 'Booking mới cần theo dõi',
            'booking_cancelled' => 'Booking đã bị hủy',
            'payment_success' => 'Booking đã thanh toán',
            'refund_requested' => 'Có yêu cầu hoàn tiền mới',
        ];

        return [
            'type' => $this->event,
            'title' => $messages[$this->event] ?? 'Cập nhật booking',
            'booking_id' => (string) $booking->id,
            'tour_id' => $tour ? (string) $tour->id : null,
            'tour_title' => $tour?->title,
            'status' => $booking->status,
            'payment_status' => $booking->payment_status,
        ];
    }
}
