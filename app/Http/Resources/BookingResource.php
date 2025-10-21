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
                'partner' => $tour->partner?->only(['id', 'company_name']),
                'media' => $tour->media,
            ] : null,
            'schedule' => $schedule ? [
                'id' => $schedule->id,
                'start_date' => optional($schedule->start_date)->toDateString(),
                'end_date' => optional($schedule->end_date)->toDateString(),
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
        $amount = (int) round($this->total_price ?? 0);
        $description = sprintf('BOOKING-%s', $this->id);

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
