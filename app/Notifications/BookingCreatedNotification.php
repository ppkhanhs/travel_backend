<?php

namespace App\Notifications;

use App\Models\Booking;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;

class BookingCreatedNotification extends Notification
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
            'type' => 'booking_created',
            'title' => 'Đặt tour thành công',
            'message' => sprintf(
                'Đơn #%s cho tour %s đã được tạo. Chúng tôi sẽ thông báo khi đối tác xác nhận.',
                $booking->id,
                $tour?->title ?? 'của bạn'
            ),
            'booking_id' => (string) $booking->id,
            'tour_id' => $tour ? (string) $tour->id : null,
            'status' => $booking->status,
            'start_date' => $startDate,
        ];
    }
}
