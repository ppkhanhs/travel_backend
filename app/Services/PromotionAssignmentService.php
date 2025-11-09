<?php

namespace App\Services;

use App\Models\Promotion;
use App\Models\PromotionAssignment;
use App\Models\Booking;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

use App\Notifications\VoucherIssuedNotification;
use App\Services\NotificationService;

class PromotionAssignmentService
{
    public function __construct(private NotificationService $notifications)
    {
    }

    public function issueVoucher(Promotion $promotion, ?string $userId, ?string $bookingId = null, array $metadata = []): PromotionAssignment
    {
        $this->guardAvailability($promotion);

        $code = $this->generateVoucherCode($promotion);

        $assignment = PromotionAssignment::create([
            'promotion_id' => $promotion->id,
            'user_id' => $userId,
            'booking_id' => $bookingId,
            'voucher_code' => $code,
            'expires_at' => $promotion->valid_to ? Carbon::parse($promotion->valid_to) : null,
            'metadata' => $metadata,
        ]);

        $assignment = $assignment->load(['promotion', 'user']);

        if ($assignment->user) {
            $this->notifications->notify($assignment->user, new VoucherIssuedNotification($assignment));
        }

        return $assignment;
    }

    public function issueAutoCancelVoucher(Booking $booking): ?PromotionAssignment
    {
        $tour = optional($booking->tourSchedule)->tour;
        $partnerId = optional($tour?->partner)->id;

        if (!$partnerId) {
            return null;
        }

        $promotion = Promotion::query()
            ->where('partner_id', $partnerId)
            ->where('type', 'voucher')
            ->where('auto_issue_on_cancel', true)
            ->where('is_active', true)
            ->orderByDesc('value')
            ->first(function (Promotion $promotion) {
                return $promotion->isActiveAt(Carbon::today()) && $this->hasAvailability($promotion);
            });

        if (!$promotion) {
            return null;
        }

        return $this->issueVoucher($promotion, $booking->user_id, $booking->id, [
            'reason' => 'auto_cancel',
        ]);
    }

    private function guardAvailability(Promotion $promotion): void
    {
        if (!$this->hasAvailability($promotion)) {
            throw new \RuntimeException('Promotion usage limit reached.');
        }
    }

    private function hasAvailability(Promotion $promotion): bool
    {
        if (is_null($promotion->max_usage)) {
            return true;
        }

        $issued = PromotionAssignment::query()
            ->where('promotion_id', $promotion->id)
            ->count();

        return $issued < $promotion->max_usage;
    }

    private function generateVoucherCode(Promotion $promotion): string
    {
        $prefix = $promotion->code ?: sprintf('VC%s', strtoupper(Str::random(4)));

        do {
            $code = sprintf('%s-%s', $prefix, strtoupper(Str::random(6)));
            $exists = PromotionAssignment::query()
                ->where('voucher_code', $code)
                ->exists();
        } while ($exists);

        return $code;
    }
}
