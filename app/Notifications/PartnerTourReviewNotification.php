<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;

class PartnerTourReviewNotification extends Notification
{
    use Queueable;

    public function __construct(
        private string $tourId,
        private string $tourTitle,
        private string $status // approved|rejected
    ) {
    }

    public function via($notifiable): array
    {
        return ['database'];
    }

    public function toArray($notifiable): array
    {
        $approved = $this->status === 'approved';

        return [
            'type' => $approved ? 'partner_tour_approved' : 'partner_tour_rejected',
            'audience' => 'partner',
            'title' => $approved ? 'Tour đã được duyệt' : 'Tour bị từ chối duyệt',
            'message' => $approved
                ? sprintf('Tour "%s" đã được admin duyệt.', $this->tourTitle)
                : sprintf('Tour "%s" bị từ chối. Vui lòng kiểm tra và cập nhật lại.', $this->tourTitle),
            'tour_id' => $this->tourId,
            'status' => $this->status,
        ];
    }
}
