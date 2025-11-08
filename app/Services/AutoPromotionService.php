<?php

namespace App\Services;

use App\Models\Promotion;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class AutoPromotionService
{
    /**
     * Attach auto promotion info to a collection of tours.
     *
     * @param  \Illuminate\Support\Collection|\Illuminate\Database\Eloquent\Collection|array  $tours
     */
    public function attachToTours($tours): void
    {
        $collection = $this->normalizeCollection($tours);

        if ($collection->isEmpty()) {
            return;
        }

        $tourIds = $collection->pluck('id')->filter()->unique();

        if ($tourIds->isEmpty()) {
            return;
        }

        $pivot = DB::table('promotion_tour')
            ->whereIn('tour_id', $tourIds)
            ->get()
            ->groupBy('tour_id');

        $promotionIds = $pivot->flatten()
            ->pluck('promotion_id')
            ->merge(
                Promotion::query()
                    ->whereIn('tour_id', $tourIds)
                    ->whereNotNull('tour_id')
                    ->pluck('id')
            )
            ->unique()
            ->filter();

        if ($promotionIds->isEmpty()) {
            $collection->each(function ($tour) {
                $tour->setAttribute('auto_promotion', null);
                $tour->setAttribute('price_after_discount', (float) ($tour->base_price ?? 0));
            });
            return;
        }

        $promotions = Promotion::query()
            ->whereIn('id', $promotionIds)
            ->where('auto_apply', true)
            ->where('is_active', true)
            ->get()
            ->keyBy('id');

        $legacyGroups = $promotions
            ->filter(fn (Promotion $promotion) => $promotion->tour_id)
            ->groupBy('tour_id');

        $today = Carbon::today();

        foreach ($collection as $tour) {
            $linkedPromotionIds = $pivot->get($tour->id, collect())->pluck('promotion_id');

            $eligiblePromotions = $linkedPromotionIds
                ->map(fn ($id) => $promotions->get($id))
                ->filter();

            if ($eligiblePromotions->isEmpty()) {
                $eligiblePromotions = $legacyGroups->get($tour->id, collect());
            }

            $promo = $this->pickPromotion($eligiblePromotions, $today, $tour->partner_id ?? null);

            if ($promo) {
                $discount = $this->calculateDiscount((float) ($tour->base_price ?? 0), $promo);
                $priceAfter = max(0, round(((float) $tour->base_price) - $discount, 2));

                $tour->setAttribute('auto_promotion', [
                    'id' => $promo->id,
                    'code' => $promo->code,
                    'description' => $promo->description,
                    'discount_type' => $promo->discount_type,
                    'value' => $promo->value,
                    'discount_amount' => $discount,
                    'valid_from' => optional($promo->valid_from)->toDateString(),
                    'valid_to' => optional($promo->valid_to)->toDateString(),
                ]);
                $tour->setAttribute('price_after_discount', $priceAfter);
            } else {
                $tour->setAttribute('auto_promotion', null);
                $tour->setAttribute('price_after_discount', (float) ($tour->base_price ?? 0));
            }
        }
    }

    /**
     * Return active auto promotions for a specific tour/schedule date.
     */
    public function getAutoPromotionsForTour(string $tourId, ?string $partnerId, ?Carbon $referenceDate = null): Collection
    {
        $date = $referenceDate ?? Carbon::today();

        $promotionIds = DB::table('promotion_tour')
            ->where('tour_id', $tourId)
            ->pluck('promotion_id');

        if ($promotionIds->isEmpty()) {
            $promotionIds = Promotion::query()
                ->where('tour_id', $tourId)
                ->pluck('id');
        }

        if ($promotionIds->isEmpty()) {
            return collect();
        }

        return Promotion::query()
            ->whereIn('id', $promotionIds)
            ->where('auto_apply', true)
            ->where('is_active', true)
            ->when($partnerId, fn ($q) => $q->where('partner_id', $partnerId))
            ->get()
            ->filter(fn (Promotion $promotion) => $promotion->isActiveAt($date))
            ->values();
    }

    private function calculateDiscount(float $amount, Promotion $promotion): float
    {
        if ($amount <= 0) {
            return 0.0;
        }

        $type = strtolower($promotion->discount_type ?? '');

        if (in_array($type, ['percent', 'percentage'], true)) {
            return round($amount * ($promotion->value / 100), 2);
        }

        return max(0.0, min($amount, (float) $promotion->value));
    }

    private function normalizeCollection($tours): Collection
    {
        if ($tours instanceof Collection) {
            return $tours;
        }

        if (is_array($tours)) {
            return collect($tours);
        }

        return collect([$tours]);
    }

    private function pickPromotion(Collection $promotions, Carbon $date, ?string $partnerId): ?Promotion
    {
        return $promotions
            ->filter(function (Promotion $promotion) use ($date, $partnerId) {
                if ($partnerId && $promotion->partner_id && $promotion->partner_id !== $partnerId) {
                    return false;
                }

                return $promotion->isActiveAt($date);
            })
            ->sortByDesc('value')
            ->first();
    }
}
