<?php

namespace App\Services;

use App\Models\AnalyticsEvent;
use App\Models\Booking;
use App\Models\RecommendationEmbedding;
use App\Models\RecommendationFeature;
use App\Models\RecommendationPopularity;
use App\Models\Tour;
use App\Models\User;
use App\Models\UserRecommendation;
use App\Models\Wishlist;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class RecommendationService
{
    public const EVENT_WEIGHTS = [
        'tour_view' => 1.0,
        'wishlist_add' => 3.0,
        'cart_add' => 4.0,
        'booking_success' => 6.0,
        'review_submit' => 5.0,
    ];

    private const HALF_LIFE_DAYS = 14;
    private const REFRESH_EVENT_THRESHOLD = 2;
    private const REFRESH_STALE_MINUTES = 30;
    private const COMPONENT_WEIGHTS = [
        'cf' => 0.6,
        'content' => 0.3,
        'popular' => 0.1,
        'fallback' => 0.05,
    ];

    private const PREFERENCE_BONUS = [
        'destination' => 0.08,
        'type' => 0.06,
        'tag' => 0.05,
    ];

    public function generateForUser(User $user, int $limit = 20): UserRecommendation
    {
        $candidateLimit = max($limit * 4, 80);
        $pipeline = $this->buildPipeline($user, $candidateLimit);

        if ($pipeline->isEmpty()) {
            $pipeline = $this->popularFallback($candidateLimit);
        }

        return $this->storeRecommendation($user, $pipeline->take($limit));
    }

    public function getRecommendations(User $user, int $limit = 10): Collection
    {
        $record = $user->recommendation;

        if ($this->shouldRefreshRecommendations($user, $record)) {
            $record = $this->generateForUser($user, max($limit, 20));
        }

        $user->setRelation('recommendation', $record);

        $data = collect($record->recommendations ?? [])->take($limit);
        $tourIds = $data->pluck('tour_id')->all();
        $tours = Tour::query()
            ->with(['partner', 'categories'])
            ->whereIn('id', $tourIds)
            ->get()
            ->keyBy('id');

        return $data->map(function (array $item) use ($tours) {
            $tour = $tours->get($item['tour_id']);

            if (!$tour) {
                return null;
            }

            return [
                'tour' => $tour,
                'score' => $item['score'],
                'reasons' => $item['reasons'] ?? [],
            ];
        })->filter();
    }

    public function similarTours(Tour $tour, int $limit = 10): Collection
    {
        $embedding = RecommendationEmbedding::query()
            ->where('entity_type', 'tour_tfidf')
            ->where('entity_id', $tour->id)
            ->first();

        if (!$embedding || empty($embedding->vector)) {
            return $this->fallbackSimilarTours($tour, $limit);
        }

        $baseVector = $embedding->vector;

        $candidates = RecommendationEmbedding::query()
            ->where('entity_type', 'tour_tfidf')
            ->where('entity_id', '!=', $tour->id)
            ->get(['entity_id', 'vector']);

        $scores = collect();

        foreach ($candidates as $candidate) {
            $score = $this->dotProductAssociative($baseVector, $candidate->vector ?? []);
            if ($score <= 0) {
                continue;
            }

            $scores->push([
                'tour_id' => (string) $candidate->entity_id,
                'score' => $score,
            ]);
        }

        if ($scores->isEmpty()) {
            return $this->fallbackSimilarTours($tour, $limit);
        }

        $max = $scores->max('score') ?: 1;

        $sorted = $scores
            ->sortByDesc('score')
            ->take(max($limit * 3, 30))
            ->map(function ($item) use ($max) {
                return [
                    'tour_id' => $item['tour_id'],
                    'score' => $max > 0 ? round($item['score'] / $max, 4) : 0.0,
                    'reasons' => ['content_match'],
                ];
            })
            ->values();

        $tourIds = $sorted->pluck('tour_id')->all();
        $tours = Tour::with('partner')
            ->whereIn('id', $tourIds)
            ->get()
            ->keyBy('id');

        return $sorted
            ->filter(fn ($item) => $tours->has($item['tour_id']))
            ->map(function ($item) use ($tours) {
                return [
                    'tour' => $tours->get($item['tour_id']),
                    'score' => $item['score'],
                    'reasons' => $item['reasons'],
                ];
            })
            ->take($limit)
            ->values();
    }

    private function buildPipeline(User $user, int $limit): Collection
    {
        $context = $this->buildUserContext($user);
        $exclude = $context['interacted_ids'];

        $candidates = [];

        $this->mergeCandidates($candidates, $this->collaborativeCandidates($user, $limit, $exclude));
        $this->mergeCandidates($candidates, $this->contentCandidates($user, $limit, $exclude));
        $this->mergeCandidates($candidates, $this->popularityCandidates($limit, $exclude));

        $this->mergeCandidates($candidates, $this->fallbackCandidates($limit, $exclude));

        if (empty($candidates)) {
            return collect();
        }

        $tourIds = array_keys($candidates);
        $features = $this->loadTourFeatures($tourIds);
        $popularityMap = $this->loadPopularityScores($tourIds);

        $ranked = $this->rerankCandidates($candidates, $features, $context, $popularityMap, $limit);

        if ($ranked->isEmpty()) {
            return collect();
        }

        return $ranked->map(fn ($item) => [
            'tour_id' => $item['tour_id'],
            'score' => $item['score'],
            'reasons' => $item['reasons'],
        ]);
    }

    private function collaborativeCandidates(User $user, int $limit, array $exclude): Collection
    {
        $embedding = RecommendationEmbedding::query()
            ->where('entity_type', 'user_cf')
            ->where('entity_id', $user->id)
            ->first();

        if (!$embedding || empty($embedding->vector)) {
            return collect();
        }

        $userVector = $embedding->vector;

        $tourEmbeddings = RecommendationEmbedding::query()
            ->where('entity_type', 'tour_cf')
            ->get(['entity_id', 'vector']);

        if ($tourEmbeddings->isEmpty()) {
            return collect();
        }

        $scores = collect();

        foreach ($tourEmbeddings as $tourEmbedding) {
            $tourId = (string) $tourEmbedding->entity_id;
            if (in_array($tourId, $exclude, true)) {
                continue;
            }

            $score = $this->dotProductNumeric($userVector, $tourEmbedding->vector ?? []);
            if ($score <= 0) {
                continue;
            }

            $scores->push([
                'tour_id' => $tourId,
                'score' => $score,
            ]);
        }

        if ($scores->isEmpty()) {
            return collect();
        }

        $max = $scores->max('score') ?: 1;

        return $scores
            ->sortByDesc('score')
            ->take(max($limit, 100))
            ->map(function ($item) use ($max) {
                $normalized = $max > 0 ? $item['score'] / $max : 0;
                return [
                    'tour_id' => $item['tour_id'],
                    'component' => 'cf',
                    'score' => round($normalized, 4),
                    'raw' => $item['score'],
                    'reasons' => ['ml_collaborative_filtering'],
                ];
            })
            ->values();
    }

    private function contentCandidates(User $user, int $limit, array $exclude): Collection
    {
        $embedding = RecommendationEmbedding::query()
            ->where('entity_type', 'user_tfidf')
            ->where('entity_id', $user->id)
            ->first();

        if (!$embedding || empty($embedding->vector)) {
            return collect();
        }

        $userVector = $embedding->vector;

        $tourEmbeddings = RecommendationEmbedding::query()
            ->where('entity_type', 'tour_tfidf')
            ->get(['entity_id', 'vector']);

        if ($tourEmbeddings->isEmpty()) {
            return collect();
        }

        $scores = collect();

        foreach ($tourEmbeddings as $tourEmbedding) {
            $tourId = (string) $tourEmbedding->entity_id;
            if (in_array($tourId, $exclude, true)) {
                continue;
            }

            $score = $this->dotProductAssociative($userVector, $tourEmbedding->vector ?? []);
            if ($score <= 0) {
                continue;
            }

            $scores->push([
                'tour_id' => $tourId,
                'score' => $score,
            ]);
        }

        if ($scores->isEmpty()) {
            return collect();
        }

        $max = $scores->max('score') ?: 1;

        return $scores
            ->sortByDesc('score')
            ->take(max($limit, 120))
            ->map(function ($item) use ($max) {
                $normalized = $max > 0 ? $item['score'] / $max : 0;
                return [
                    'tour_id' => $item['tour_id'],
                    'component' => 'content',
                    'score' => round($normalized, 4),
                    'raw' => $item['score'],
                    'reasons' => ['content_match'],
                ];
            })
            ->values();
    }

    private function popularityCandidates(int $limit, array $exclude): Collection
    {
        $popularities = RecommendationPopularity::query()
            ->orderByDesc('score')
            ->limit(max($limit, 120))
            ->get(['tour_id', 'score']);

        if ($popularities->isEmpty()) {
            return collect();
        }

        $max = $popularities->max('score') ?: 1;

        return $popularities
            ->filter(fn ($pop) => !in_array((string) $pop->tour_id, $exclude, true))
            ->map(function ($pop) use ($max) {
                $normalized = $max > 0 ? $pop->score / $max : 0;
                return [
                    'tour_id' => (string) $pop->tour_id,
                    'component' => 'popular',
                    'score' => round($normalized, 4),
                    'raw' => (float) $pop->score,
                    'reasons' => ['popular'],
                ];
            })
            ->values();
    }

    private function fallbackCandidates(int $limit, array $exclude): Collection
    {
        return $this->popularFallback($limit, $exclude)
            ->map(function ($item) {
                return [
                    'tour_id' => $item['tour_id'],
                    'component' => 'fallback',
                    'score' => 0.4,
                    'raw' => $item['score'],
                    'reasons' => array_unique(array_merge($item['reasons'] ?? [], ['fallback'])),
                ];
            });
    }

    private function mergeCandidates(array &$bucket, Collection $candidates): void
    {
        foreach ($candidates as $item) {
            $tourId = $item['tour_id'];
            $component = $item['component'];
            $bucket[$tourId]['tour_id'] = $tourId;
            $bucket[$tourId]['components'][$component] = max($bucket[$tourId]['components'][$component] ?? 0, $item['score']);
            $bucket[$tourId]['reasons'] = array_values(array_unique(array_merge($bucket[$tourId]['reasons'] ?? [], $item['reasons'] ?? [])));
            if (isset($item['raw'])) {
                $bucket[$tourId]['raw'][$component] = $item['raw'];
            }
        }
    }

    private function rerankCandidates(array $bucket, array $features, array $context, array $popularityMap, int $limit): Collection
    {
        $results = [];
        foreach ($bucket as $tourId => $data) {
            $components = $data['components'] ?? [];
            $score = 0.0;

            foreach ($components as $component => $value) {
                $score += (self::COMPONENT_WEIGHTS[$component] ?? 0) * $value;
            }

            $feature = $features[$tourId] ?? null;
            $score += $this->preferenceBoost($feature, $context);

            if (isset($popularityMap[$tourId])) {
                $score += 0.02 * $popularityMap[$tourId];
            }

            $results[] = [
                'tour_id' => $tourId,
                'score' => round($score, 4),
                'reasons' => $data['reasons'] ?? [],
            ];
        }

        return collect($results)
            ->sortByDesc('score')
            ->values()
            ->take($limit * 2);
    }

    private function preferenceBoost(?array $feature, array $context): float
    {
        if (!$feature) {
            return 0.0;
        }

        $bonus = 0.0;

        if (!empty($feature['destination']) && ($context['favorite_destinations']->get($feature['destination']) ?? 0) > 0) {
            $bonus += self::PREFERENCE_BONUS['destination'];
        }

        if (!empty($feature['type']) && ($context['favorite_types']->get($feature['type']) ?? 0) > 0) {
            $bonus += self::PREFERENCE_BONUS['type'];
        }

        if (!empty($feature['tags'])) {
            $matches = array_filter($feature['tags'], function ($tag) use ($context) {
                return $context['favorite_tags']->get($tag) ?? 0;
            });

            if (!empty($matches)) {
                $bonus += self::PREFERENCE_BONUS['tag'];
            }
        }

        return $bonus;
    }

    private function loadTourFeatures(array $tourIds): array
    {
        if (empty($tourIds)) {
            return [];
        }

        $stored = RecommendationFeature::query()
            ->whereIn('tour_id', $tourIds)
            ->get(['tour_id', 'features'])
            ->keyBy('tour_id');

        $missing = array_diff($tourIds, $stored->keys()->all());

        if (!empty($missing)) {
            $tours = Tour::query()
                ->whereIn('id', $missing)
                ->get(['id', 'destination', 'type', 'tags', 'duration', 'base_price', 'child_age_limit', 'requires_passport', 'requires_visa']);

            foreach ($tours as $tour) {
                $stored[$tour->id] = new RecommendationFeature([
                    'tour_id' => $tour->id,
                    'features' => [
                        'destination' => $tour->destination,
                        'type' => $tour->type,
                        'tags' => $tour->tags ?? [],
                        'duration' => (int) $tour->duration,
                        'base_price' => (float) $tour->base_price,
                        'child_age_limit' => (int) $tour->child_age_limit,
                        'requires_passport' => (bool) $tour->requires_passport,
                        'requires_visa' => (bool) $tour->requires_visa,
                        'avg_rating' => null,
                        'rating_count' => 0,
                    ],
                ]);
            }
        }

        return $stored->map(fn ($feature) => $feature->features ?? [])->all();
    }

    private function loadPopularityScores(array $tourIds): array
    {
        if (empty($tourIds)) {
            return [];
        }

        $popularities = RecommendationPopularity::query()
            ->whereIn('tour_id', $tourIds)
            ->get(['tour_id', 'score'])
            ->keyBy('tour_id');

        $max = $popularities->max('score') ?: 1;

        return $popularities->map(function ($item) use ($max) {
            return $max > 0 ? $item->score / $max : 0;
        })->all();
    }

    private function shouldRefreshRecommendations(User $user, ?UserRecommendation $record): bool
    {
        if (!$record || empty($record->recommendations)) {
            return true;
        }

        $generatedAt = $record->generated_at;

        if (!$generatedAt || $generatedAt->lte(now()->subMinutes(self::REFRESH_STALE_MINUTES))) {
            return true;
        }

        $newEvents = AnalyticsEvent::query()
            ->where('user_id', $user->id)
            ->whereIn('event_name', array_keys(self::EVENT_WEIGHTS))
            ->when($generatedAt, fn ($query) => $query->where('occurred_at', '>', $generatedAt))
            ->limit(self::REFRESH_EVENT_THRESHOLD)
            ->count();

        return $newEvents >= self::REFRESH_EVENT_THRESHOLD;
    }

    private function buildUserContext(User $user): array
    {
        $events = AnalyticsEvent::query()
            ->where('user_id', $user->id)
            ->whereIn('event_name', array_keys(self::EVENT_WEIGHTS))
            ->where('occurred_at', '>=', now()->subYear())
            ->orderByDesc('occurred_at')
            ->get();

        $eventTourIds = $events
            ->filter(fn (AnalyticsEvent $event) => $event->entity_type === 'tour' && $event->entity_id)
            ->pluck('entity_id');

        $bookedTourIds = Booking::query()
            ->where('user_id', $user->id)
            ->join('tour_schedules', 'bookings.tour_schedule_id', '=', 'tour_schedules.id')
            ->pluck('tour_schedules.tour_id');

        $wishlistTourIds = Wishlist::query()
            ->where('user_id', $user->id)
            ->pluck('tour_id');

        $interactedIds = collect([$eventTourIds, $bookedTourIds, $wishlistTourIds])
            ->flatten()
            ->filter()
            ->map(fn ($id) => (string) $id)
            ->unique()
            ->values();

        $preferenceTours = Tour::query()
            ->whereIn('id', $interactedIds)
            ->get(['id', 'tags', 'destination', 'type']);

        return [
            'interacted_ids' => $interactedIds->all(),
            'favorite_tags' => $preferenceTours->pluck('tags')->filter()->flatten()->countBy()->sortDesc(),
            'favorite_destinations' => $preferenceTours->pluck('destination')->filter()->countBy()->sortDesc(),
            'favorite_types' => $preferenceTours->pluck('type')->filter()->countBy()->sortDesc(),
        ];
    }

    private function fallbackSimilarTours(Tour $tour, int $limit): Collection
    {
        $tags = collect($tour->tags ?? []);

        $candidates = Tour::approved()
            ->where('id', '!=', $tour->id)
            ->with('partner')
            ->get(['id', 'tags', 'destination', 'type', 'title', 'media']);

        return $candidates
            ->map(function (Tour $candidate) use ($tour, $tags) {
                $tagScore = $this->tagSimilarityScore($candidate->tags ?? [], $tags->countBy());
                $destinationScore = $candidate->destination === $tour->destination ? 1 : 0;
                $typeScore = $candidate->type === $tour->type ? 0.5 : 0;
                $score = ($tagScore * 3) + $destinationScore + $typeScore;

                return [
                    'tour' => $candidate,
                    'score' => round($score, 4),
                    'reasons' => array_filter([
                        $tagScore > 0 ? 'shared_tags' : null,
                        $destinationScore > 0 ? 'same_destination' : null,
                        $typeScore > 0 ? 'same_type' : null,
                    ]),
                ];
            })
            ->filter(fn ($item) => $item['score'] > 0)
            ->sortByDesc('score')
            ->take($limit)
            ->values();
    }

    public function popularFallback(int $limit, array $excludeTourIds = []): Collection
    {
        $excludeTourIds = array_map('strval', $excludeTourIds);

        $popularTourIds = Booking::query()
            ->join('tour_schedules', 'bookings.tour_schedule_id', '=', 'tour_schedules.id')
            ->select('tour_schedules.tour_id', DB::raw('COUNT(bookings.id) as score'))
            ->groupBy('tour_schedules.tour_id')
            ->orderByDesc('score')
            ->pluck('score', 'tour_schedules.tour_id');

        $results = collect();

        foreach ($popularTourIds as $tourId => $score) {
            if (in_array((string) $tourId, $excludeTourIds, true)) {
                continue;
            }

            $results->push([
                'tour_id' => (string) $tourId,
                'score' => (float) $score,
                'reasons' => ['popular'],
            ]);

            if ($results->count() >= $limit) {
                break;
            }
        }

        if ($results->isEmpty()) {
            $fallbackTours = Tour::approved()
                ->orderByDesc('created_at')
                ->limit(max(10, $limit * 3))
                ->pluck('id')
                ->map(fn ($id) => (string) $id)
                ->filter(fn ($id) => !in_array($id, $excludeTourIds, true))
                ->take($limit);

            return $fallbackTours->map(fn ($id) => [
                'tour_id' => $id,
                'score' => 1,
                'reasons' => ['recent'],
            ]);
        }

        return $results->values();
    }

    private function storeRecommendation(User $user, Collection $entries): UserRecommendation
    {
        return UserRecommendation::updateOrCreate(
            ['user_id' => $user->id],
            [
                'recommendations' => $entries->values()->all(),
                'generated_at' => now(),
            ]
        );
    }

    private function dotProductNumeric(array $vectorA, array $vectorB): float
    {
        $length = min(count($vectorA), count($vectorB));
        $sum = 0.0;

        for ($i = 0; $i < $length; $i++) {
            $sum += ((float) $vectorA[$i]) * ((float) $vectorB[$i]);
        }

        return $sum;
    }

    private function dotProductAssociative(array $vectorA, array $vectorB): float
    {
        $sum = 0.0;
        $smaller = count($vectorA) <= count($vectorB) ? $vectorA : $vectorB;
        $larger = $smaller === $vectorA ? $vectorB : $vectorA;

        foreach ($smaller as $term => $weight) {
            if (!isset($larger[$term])) {
                continue;
            }

            $sum += ((float) $weight) * ((float) $larger[$term]);
        }

        return $sum;
    }

    public function decayFactor(int $days): float
    {
        if ($days <= 0) {
            return 1.0;
        }

        return pow(0.5, $days / self::HALF_LIFE_DAYS);
    }

    private function tagSimilarityScore($candidateTags, $favoriteTags): float
    {
        $candidateTags = collect($candidateTags)->filter();
        if ($candidateTags->isEmpty() || $favoriteTags->isEmpty()) {
            return 0.0;
        }

        $matches = $candidateTags
            ->filter(fn ($tag) => $favoriteTags->has($tag))
            ->map(fn ($tag) => $favoriteTags->get($tag))
            ->sum();

        if ($matches <= 0) {
            return 0.0;
        }

        return $matches / max(1, $favoriteTags->sum());
    }
}


