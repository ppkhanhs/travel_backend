<?php

namespace App\Notifications;

use App\Models\Booking;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;

class BookingReviewRequestNotification extends Notification
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
            'type' => 'booking_review_request',
            'audience' => 'customer',
            'title' => 'Chia sẻ cảm nhận của bạn',
            'message' => sprintf(
                'Hãy đánh giá tour %s để giúp các khách hàng khác lựa chọn tốt hơn.',
                $tour?->title ?? ''
            ),
            'booking_id' => (string) $booking->id,
            'tour_id' => $tour ? (string) $tour->id : null,
            'status' => $booking->status,
        ];
    }
}
