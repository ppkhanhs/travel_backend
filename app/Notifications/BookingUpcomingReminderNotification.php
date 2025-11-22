<?php

namespace App\Notifications;

use App\Models\Booking;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class BookingUpcomingReminderNotification extends Notification
{
    use Queueable;

    public function __construct(private Booking $booking, private int $daysAhead)
    {
    }

    public function via($notifiable): array
    {
        return ['database', 'mail'];
    }

    public function toArray($notifiable): array
    {
        $booking = $this->booking;
        $tour = $booking->tourSchedule?->tour;
        $start = $booking->tourSchedule?->start_date;
        $startDate = $start instanceof \Illuminate\Support\Carbon ? $start->toDateString() : ($start ? (string) $start : null);
        $pickup = $booking->tourSchedule?->pickup_location;
        $hotline = $booking->tourSchedule?->hotline;

        return [
            'type' => 'booking_upcoming',
            'audience' => 'customer',
            'title' => $this->daysAhead === 5
                ? 'Tour sắp khởi hành (còn 5 ngày)'
                : 'Tour sắp khởi hành (còn 2 ngày)',
            'message' => $this->buildMessage($tour?->title, $startDate, $pickup, $hotline),
            'booking_id' => (string) $booking->id,
            'tour_id' => $tour ? (string) $tour->id : null,
            'status' => $booking->status,
            'start_date' => $startDate,
            'pickup_location' => $pickup,
            'hotline' => $hotline,
        ];
    }

    public function toMail($notifiable): MailMessage
    {
        $booking = $this->booking;
        $tour = $booking->tourSchedule?->tour;
        $start = $booking->tourSchedule?->start_date;
        $startDate = $start instanceof \Illuminate\Support\Carbon ? $start->toDateString() : ($start ? (string) $start : null);
        $pickup = $booking->tourSchedule?->pickup_location;
        $hotline = $booking->tourSchedule?->hotline;

        $mail = (new MailMessage())
            ->subject($this->daysAhead === 5
                ? 'Nhắc chuẩn bị tour (còn 5 ngày)'
                : 'Nhắc chuẩn bị tour (còn 2 ngày)')
            ->greeting('Xin chào ' . ($notifiable->name ?? 'bạn'))
            ->line($this->buildMessage($tour?->title, $startDate, $pickup, $hotline))
            ->line(sprintf('Mã booking: %s', $booking->id))
            ->line(sprintf('Ngày khởi hành: %s', $startDate ?? 'N/A'))
            ->line(sprintf('Tình trạng thanh toán: %s', $booking->payment_status ?? 'chưa cập nhật'));

        if ($pickup) {
            $mail->line('Điểm đón: ' . $pickup);
        }

        if ($hotline) {
            $mail->line('Hotline điều hành/Hướng dẫn: ' . $hotline);
        }

        return $mail->line('Chúc bạn có chuyến đi vui vẻ!');
    }

    private function buildMessage(?string $tourTitle, ?string $startDate, ?string $pickup, ?string $hotline): string
    {
        if ($this->daysAhead === 5) {
            return sprintf(
                'Tour %s sẽ khởi hành ngày %s. Vui lòng chuẩn bị giấy tờ, hành lý; kiểm tra voucher/mã booking/hóa đơn; xác nhận điểm đón%s%s.',
                $tourTitle ?? '',
                $startDate ?? 'sắp tới',
                $pickup ? (' tại: ' . $pickup) : '',
                $hotline ? ('. Hotline: ' . $hotline) : ''
            );
        }

        return sprintf(
            'Tour %s sẽ khởi hành ngày %s. Vui lòng kiểm tra giờ tập trung, local guide; xác nhận đã thanh toán đủ tiền%s%s.',
            $tourTitle ?? '',
            $startDate ?? 'sắp tới',
            $pickup ? ('; điểm đón: ' . $pickup) : '',
            $hotline ? ('; hotline: ' . $hotline) : ''
        );
    }
}
