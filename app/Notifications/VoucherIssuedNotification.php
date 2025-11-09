<?php

namespace App\Notifications;

use App\Models\PromotionAssignment;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;

class VoucherIssuedNotification extends Notification
{
    use Queueable;

    public function __construct(private PromotionAssignment $assignment)
    {
    }

    public function via($notifiable): array
    {
        return ['database'];
    }

    public function toArray($notifiable): array
    {
        $promotion = $this->assignment->promotion;

        return [
            'type' => 'voucher',
            'title' => 'Voucher mới đã được tặng',
            'message' => sprintf(
                'Mã %s giảm %s đã được tặng cho bạn.',
                $this->assignment->voucher_code,
                $promotion && in_array(strtolower($promotion->discount_type ?? ''), ['percent', 'percentage'])
                    ? $promotion->value . '%'
                    : number_format($promotion->value ?? 0, 0, '.', ',') . ' VND'
            ),
            'voucher_code' => $this->assignment->voucher_code,
            'promotion_id' => $promotion?->id,
            'booking_id' => $this->assignment->booking_id,
            'expires_at' => optional($this->assignment->expires_at)->toIso8601String(),
        ];
    }
}

