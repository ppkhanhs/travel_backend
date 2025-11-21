<?php

namespace App\Notifications;

use App\Models\Booking;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;

class PartnerBookingNotification extends Notification
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
            'booking_created' => 'Có booking mới cần xác nhận',
            'booking_cancelled' => 'Khách đã hủy booking',
            'payment_success' => 'Booking đã thanh toán thành công',
            'refund_requested' => 'Khách gửi yêu cầu hoàn tiền',
        ];
        $details = [
            'booking_created' => 'Xem chi tiết để xác nhận hoặc liên hệ khách.',
            'booking_cancelled' => 'Xem chi tiết để cập nhật chỗ trống.',
            'payment_success' => 'Khách đã thanh toán, chuẩn bị dịch vụ.',
            'refund_requested' => 'Xem và xử lý yêu cầu hoàn tiền của khách.',
        ];

        return [
            'type' => $this->event,
            'title' => $messages[$this->event] ?? 'Cập nhật booking',
            'message' => $details[$this->event] ?? null,
            'booking_id' => (string) $booking->id,
            'tour_id' => $tour ? (string) $tour->id : null,
            'tour_title' => $tour?->title,
            'status' => $booking->status,
            'payment_status' => $booking->payment_status,
        ];
    }
}
