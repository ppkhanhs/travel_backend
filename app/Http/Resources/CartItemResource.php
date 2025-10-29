<?php

namespace App\Http\Resources;

use Illuminate\Http\Resources\Json\JsonResource;

class CartItemResource extends JsonResource
{
    public function toArray($request): array
    {
        $tour = $this->whenLoaded('tour');
        $schedule = $this->whenLoaded('schedule');
        $package = $this->whenLoaded('package');

        $unitPrice = $package
            ? (float) ($package->adult_price ?? 0)
            : (float) ($tour->base_price ?? 0);

        $adultPrice = $unitPrice * (int) $this->adult_quantity;
        $childUnit = $package
            ? (float) ($package->child_price ?? $package->adult_price ?? 0)
            : (float) ($tour->base_price ?? 0) * 0.75;
        $childPrice = $childUnit * (int) $this->child_quantity;

        return [
            'id' => $this->id,
            'tour_id' => $this->tour_id,
            'schedule_id' => $this->schedule_id,
            'package_id' => $this->package_id,
            'adult_quantity' => (int) $this->adult_quantity,
            'child_quantity' => (int) $this->child_quantity,
            'pricing' => [
                'adult_unit' => $unitPrice,
                'child_unit' => $childUnit,
                'adult_subtotal' => $adultPrice,
                'child_subtotal' => $childPrice,
                'subtotal' => $adultPrice + $childPrice,
            ],
            'tour' => $tour ? [
                'id' => $tour->id,
                'title' => $tour->title,
                'destination' => $tour->destination,
                'type' => $tour->type,
                'child_age_limit' => $tour->child_age_limit,
                'requires_passport' => (bool) $tour->requires_passport,
                'requires_visa' => (bool) $tour->requires_visa,
                'media' => $tour->media,
                'cancellation_policies' => $tour->relationLoaded('cancellationPolicies')
                    ? $tour->cancellationPolicies->map(function ($policy) {
                        return [
                            'id' => $policy->id,
                            'days_before' => $policy->days_before,
                            'refund_rate' => $policy->refund_rate,
                            'description' => $policy->description,
                        ];
                    })->values()
                    : [],
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
        ];
    }
}
