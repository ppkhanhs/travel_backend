<?php

namespace App\Http\Resources;

use Illuminate\Http\Resources\Json\JsonResource;

class RecommendationResource extends JsonResource
{
    public function toArray($request): array
    {
        $data = is_array($this->resource) ? $this->resource : $this->resource->toArray();

        $tour = $data['tour'] ?? null;

        if ($tour instanceof \App\Models\Tour) {
            $tourData = [
                'id' => (string) $tour->id,
                'title' => $tour->title,
                'destination' => $tour->destination,
                'type' => $tour->type,
                'duration' => $tour->duration,
                'base_price' => (float) $tour->base_price,
                'tags' => $tour->tags,
                'media' => $tour->media,
                'child_age_limit' => $tour->child_age_limit,
                'requires_passport' => (bool) $tour->requires_passport,
                'requires_visa' => (bool) $tour->requires_visa,
                'partner' => $tour->relationLoaded('partner') && $tour->partner
                    ? $tour->partner->only(['id', 'company_name'])
                    : null,
            ];
        } else {
            $tourData = null;
        }

        return [
            'tour_id' => isset($tourData['id']) ? $tourData['id'] : ($data['tour_id'] ?? null),
            'score' => $data['score'] ?? null,
            'reasons' => $data['reasons'] ?? [],
            'tour' => $tourData,
        ];
    }
}
