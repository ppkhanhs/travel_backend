<?php

namespace App\Notifications;

use App\Models\RefundRequest;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;

class RefundRequestUpdatedNotification extends Notification
{
    use Queueable;

    public function __construct(private RefundRequest $refund)
    {
    }

    public function via($notifiable): array
    {
        return ['database'];
    }

    public function toArray($notifiable): array
    {
        return [
            'type' => 'refund_update',
            'audience' => 'partner',
            'title' => 'Yêu cầu hoàn tiền đã cập nhật',
            'message' => sprintf(
                'Trạng thái yêu cầu hoàn tiền cho đơn %s hiện là %s.',
                $this->refund->booking_id,
                $this->refund->status
            ),
            'refund_request_id' => $this->refund->id,
            'booking_id' => $this->refund->booking_id,
            'status' => $this->refund->status,
        ];
    }
}
