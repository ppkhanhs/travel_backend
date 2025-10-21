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
        ];
    }
}

