<?php

namespace App\Notifications;

use App\Models\RefundRequest;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;

class RefundRequestCreatedNotification extends Notification
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
            'type' => 'refund_request',
            'title' => 'Yêu cầu hoàn tiền mới',
            'message' => sprintf(
                'Khách hàng yêu cầu hoàn %s VND cho đơn %s.',
                number_format($this->refund->amount, 0, '.', ','),
                $this->refund->booking_id
            ),
            'refund_request_id' => $this->refund->id,
            'booking_id' => $this->refund->booking_id,
            'status' => $this->refund->status,
        ];
    }
}

