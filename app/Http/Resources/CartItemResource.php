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
        $adultCount = max(0, (int) $this->adult_quantity);
        $childCount = max(0, (int) $this->child_quantity);

        $baseAdultUnit = $package
            ? (float) ($package->adult_price ?? 0)
            : (float) ($tour->price_after_discount ?? $tour->base_price ?? 0);
        $baseChildUnit = $package
            ? (float) ($package->child_price ?? $package->adult_price ?? 0)
            : $baseAdultUnit * 0.75;

        $subtotal = ($baseAdultUnit * $adultCount) + ($baseChildUnit * $childCount);
        $effectiveAdult = $baseAdultUnit;
        $effectiveChild = $baseChildUnit;

        return [
            'id' => $this->id,
            'tour_id' => $this->tour_id,
            'schedule_id' => $this->schedule_id,
            'package_id' => $this->package_id,
            'adult_quantity' => (int) $this->adult_quantity,
            'child_quantity' => (int) $this->child_quantity,
            'pricing' => [
                'adult_unit' => $effectiveAdult,
                'child_unit' => $effectiveChild,
                'adult_subtotal' => $effectiveAdult * $adultCount,
                'child_subtotal' => $effectiveChild * $childCount,
                'subtotal' => $subtotal,
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
                'price_after_discount' => $tour->price_after_discount ?? $tour->base_price,
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
                'min_participants' => $schedule->min_participants,
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
