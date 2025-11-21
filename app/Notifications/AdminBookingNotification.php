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
        $details = [
            'booking_created' => 'Kiểm tra và theo dõi tiến độ xác nhận của đối tác.',
            'booking_cancelled' => 'Cập nhật báo cáo và các chỉ số liên quan.',
            'payment_success' => 'Đối chiếu thanh toán, hỗ trợ nếu có phát sinh.',
            'refund_requested' => 'Xem và giám sát tiến trình xử lý hoàn tiền.',
        ];

        return [
            'type' => $this->event,
            'audience' => 'admin',
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
