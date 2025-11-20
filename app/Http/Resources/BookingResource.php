<?php

namespace App\Http\Resources;

use Illuminate\Http\Resources\Json\JsonResource;

class BookingResource extends JsonResource
{
    public function toArray($request): array
    {
        $schedule = $this->whenLoaded('tourSchedule');
        $tour = $schedule?->tour;
        $package = $this->whenLoaded('package');
        $payments = $this->whenLoaded('payments');
        $policies = $tour?->relationLoaded('cancellationPolicies') ? $tour->cancellationPolicies : collect();
        $promotions = $this->whenLoaded('promotions');
        $promotionCollection = $promotions ?? collect();
        $refunds = $this->whenLoaded('refundRequests');

        return [
            'id' => $this->id,
            'status' => $this->status,
            'payment_status' => $this->payment_status,
            'booking_date' => optional($this->booking_date)->toIso8601String(),
            'total_price' => $this->total_price,
            'total_adults' => $this->total_adults,
            'total_children' => $this->total_children,
            'contact' => [
                'name' => $this->contact_name,
                'email' => $this->contact_email,
                'phone' => $this->contact_phone,
                'notes' => $this->notes,
            ],
            'tour' => $tour ? [
                'id' => $tour->id,
                'title' => $tour->title,
                'destination' => $tour->destination,
                'type' => $tour->type,
                'child_age_limit' => $tour->child_age_limit,
                'requires_passport' => (bool) $tour->requires_passport,
                'requires_visa' => (bool) $tour->requires_visa,
                'partner' => $tour->partner?->only(['id', 'company_name']),
                'media' => $tour->media,
                'cancellation_policies' => $policies->map(function ($policy) {
                    return [
                        'id' => $policy->id,
                        'days_before' => $policy->days_before,
                        'refund_rate' => $policy->refund_rate,
                        'description' => $policy->description,
                    ];
                })->values(),
            ] : null,
            'schedule' => $schedule ? [
                'id' => $schedule->id,
                'start_date' => optional($schedule->start_date)->toDateString(),
                'end_date' => optional($schedule->end_date)->toDateString(),
                'min_participants' => $schedule->min_participants,
            ] : null,
            'package' => $package ? [
                'id' => $package->id,
                'name' => $package->name,
                'adult_price' => (float) $package->adult_price,
                'child_price' => (float) $package->child_price,
            ] : null,
            'passengers' => PassengerResource::collection($this->whenLoaded('passengers')),
            'payments' => PaymentResource::collection($this->whenLoaded('payments')),
            'review' => $this->whenLoaded('review', function () {
                return $this->review ? [
                    'id' => $this->review->id,
                    'rating' => $this->review->rating,
                    'comment' => $this->review->comment,
                    'created_at' => optional($this->review->created_at)->toIso8601String(),
                ] : null;
            }),
            'promotions' => $promotionCollection->map(function ($promotion) {
                return [
                    'id' => $promotion->id,
                    'code' => $promotion->code,
                    'discount_type' => $promotion->pivot->discount_type ?? $promotion->discount_type,
                    'value' => $promotion->value,
                    'discount_amount' => (float) ($promotion->pivot->discount_amount ?? 0),
                ];
            })->values(),
            'discount_total' => (float) $promotionCollection->sum(function ($promotion) {
                return (float) ($promotion->pivot->discount_amount ?? 0);
            }),
            'refund_requests' => $refunds ? $refunds->map(function ($refund) {
                return [
                    'id' => $refund->id,
                    'status' => $refund->status,
                    'amount' => $refund->amount,
                    'currency' => $refund->currency,
                    'bank_account_name' => $refund->bank_account_name,
                    'bank_account_number' => $refund->bank_account_number,
                    'bank_name' => $refund->bank_name,
                    'bank_branch' => $refund->bank_branch,
                    'customer_message' => $refund->customer_message,
                    'partner_message' => $refund->partner_message,
                    'proof_url' => $refund->proof_url,
                    'partner_marked_at' => optional($refund->partner_marked_at)->toIso8601String(),
                    'customer_confirmed_at' => optional($refund->customer_confirmed_at)->toIso8601String(),
                    'created_at' => optional($refund->created_at)->toIso8601String(),
                ];
            })->values() : null,
            'can_cancel' => in_array($this->status, ['pending', 'confirmed'], true),
            'payment_qr_url' => $this->generateSepayQrUrl($payments),
        ];
    }

    private function generateSepayQrUrl($payments): ?string
    {
        if (!$payments || !$payments->contains(fn ($payment) => $payment->method === 'sepay')) {
            return null;
        }

        $account = config('sepay.account');
        $bank = config('sepay.bank');
        if (!$account || !$bank) {
            return null;
        }

        $baseUrl = rtrim(config('sepay.qr_url', 'https://qr.sepay.vn/img'), '/');
        // Ưu tiên số tiền thực thu của payment (đã trừ khuyến mãi)
        $latestSepay = $payments
            ->where('method', 'sepay')
            ->sortByDesc(fn ($payment) => [$payment->paid_at, $payment->id])
            ->first();

        $amount = (int) max(
            0,
            round(($latestSepay?->amount ?? 0) - ($latestSepay?->discount_amount ?? 0))
        );
        if ($amount === 0) {
            $amount = (int) round($this->total_price ?? 0);
        }

        $pattern = (string) config('sepay.pattern', 'BOOKING-');
        $description = sprintf('%s%s', $pattern, $this->id);

        return sprintf(
            '%s?%s',
            $baseUrl,
            http_build_query([
                'acc' => $account,
                'bank' => $bank,
                'amount' => $amount,
                'des' => $description,
                'template' => 'compact',
            ])
        );
    }
}
