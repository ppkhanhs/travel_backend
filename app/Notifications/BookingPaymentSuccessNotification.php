<?php

namespace App\Notifications;

use App\Models\Booking;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;

class BookingPaymentSuccessNotification extends Notification
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
            'type' => 'payment_success',
            'title' => 'Thanh toán thành công',
            'message' => sprintf(
                'Thanh toán cho đơn #%s đã được xác nhận. Chúng tôi sẽ cập nhật tình trạng tour trong thời gian sớm nhất.',
                $booking->id
            ),
            'booking_id' => (string) $booking->id,
            'tour_id' => $tour ? (string) $tour->id : null,
            'status' => $booking->status,
            'start_date' => $startDate,
        ];
    }
}
