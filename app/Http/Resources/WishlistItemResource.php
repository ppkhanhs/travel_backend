<?php

namespace App\Http\Resources;

use Illuminate\Http\Resources\Json\JsonResource;

class WishlistItemResource extends JsonResource
{
    public function toArray($request): array
    {
        $tour = $this->whenLoaded('tour');

        $status = $tour?->status;
        $available = $tour && $status === 'approved';

        $packages = $tour?->packages ?? collect();
        $schedules = $tour?->schedules ?? collect();

        return [
            'id' => $this->id,
            'tour_id' => $this->tour_id,
            'added_at' => optional($this->created_at)->toIso8601String(),
            'available' => $available,
            'status' => $tour ? $tour->status : 'deleted',
            'tour' => $tour ? [
                'id' => $tour->id,
                'title' => $tour->title,
                'destination' => $tour->destination,
                'duration' => $tour->duration,
                'base_price' => (float) $tour->base_price,
                'type' => $tour->type,
                'child_age_limit' => $tour->child_age_limit,
                'requires_passport' => (bool) $tour->requires_passport,
                'requires_visa' => (bool) $tour->requires_visa,
                'media' => $tour->media,
                'policy' => $tour->policy,
                'itinerary' => $tour->itinerary,
                'average_rating' => (float) ($tour->rating_average ?? 0),
                'rating_count' => (int) ($tour->rating_count ?? 0),
                'packages' => $packages->map(function ($package) {
                    return [
                        'id' => $package->id,
                        'name' => $package->name,
                        'adult_price' => (float) $package->adult_price,
                        'child_price' => (float) $package->child_price,
                    ];
                }),
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
                'schedules' => $schedules->map(function ($schedule) {
                    return [
                        'id' => $schedule->id,
                        'start_date' => optional($schedule->start_date)->toDateString(),
                        'end_date' => optional($schedule->end_date)->toDateString(),
                        'seats_total' => $schedule->seats_total,
                        'seats_available' => $schedule->seats_available,
                        'min_participants' => $schedule->min_participants,
                        'season_price' => $schedule->season_price,
                    ];
                }),
            ] : null,
        ];
    }
}
