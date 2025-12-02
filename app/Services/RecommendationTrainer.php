<?php

namespace App\Services;

use App\Models\AnalyticsEvent;
use App\Models\Booking;
use App\Models\RecommendationEmbedding;
use App\Models\RecommendationFeature;
use App\Models\RecommendationPopularity;
use App\Models\Tour;
use App\Models\UserActivityLog;
use App\Models\User;
use App\Models\Wishlist;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class RecommendationTrainer
{
    private const CONTENT_WEIGHT = 2.0;
    private const CONTENT_MIN_SIMILARITY = 0.05;
    private const TOKEN_MIN_LENGTH = 2;

    private RecommendationService $service;

    public function __construct(RecommendationService $service)
    {
        $this->service = $service;
    }

    /**
     * Train collaborative filtering model and persist recommendations per user.
     *
     * @return int Number of users updated.
     */
    public function train(
        int $factors = 16,
        int $iterations = 15,
        float $learningRate = 0.05,
        float $regularization = 0.01,
        int $top = 50
    ): int {
        $dataset = $this->buildInteractionDataset();

        if (empty($dataset['ratings'])) {
            return 0;
        }

        $userIds = $dataset['user_ids'];
        $cfTourIds = $dataset['tour_ids'];
        $ratings = $dataset['ratings'];
        $userTourScores = $dataset['user_tour_scores'];

        $userIndex = array_flip($userIds);
        $tourIndex = array_flip($cfTourIds);

        $userCount = count($userIds);
        $itemCount = count($cfTourIds);

        if ($userCount === 0 || $itemCount === 0) {
            return 0;
        }

        $allTourIds = Tour::approved()->pluck('id')->map(fn ($id) => (string) $id)->all();
        $tfidfVectors = $this->buildTfidfVectors($allTourIds);

        $userFactors = $this->initializeFactors($userCount, $factors);
        $itemFactors = $this->initializeFactors($itemCount, $factors);

        $learningRate = max(0.0001, $learningRate);
        $regularization = max(0.0001, $regularization);
        $iterations = max(1, $iterations);

        for ($iter = 0; $iter < $iterations; $iter++) {
            shuffle($ratings);

            foreach ($ratings as [$uIdx, $iIdx, $rating]) {
                $prediction = $this->dotProduct($userFactors[$uIdx], $itemFactors[$iIdx]);
                $error = $rating - $prediction;

                for ($k = 0; $k < $factors; $k++) {
                    $userValue = $userFactors[$uIdx][$k];
                    $itemValue = $itemFactors[$iIdx][$k];

                    $userFactors[$uIdx][$k] += $learningRate * ($error * $itemValue - $regularization * $userValue);
                    $itemFactors[$iIdx][$k] += $learningRate * ($error * $userValue - $regularization * $itemValue);
                }
            }
        }

        $this->persistCollaborativeEmbeddings($userIds, $userFactors, $cfTourIds, $itemFactors);
        $this->persistTourContentEmbeddings($tfidfVectors);

        $userProfiles = [];
        foreach ($userIds as $userId) {
            $userProfiles[$userId] = $this->buildUserContentVector($userTourScores[$userId] ?? [], $tfidfVectors);
        }
        $this->persistUserContentEmbeddings($userProfiles);

        $this->refreshFeatures($allTourIds);
        $this->refreshPopularity($allTourIds);

        $users = User::query()
            ->whereIn('id', $userIds)
            ->get()
            ->keyBy('id');

        $updatedUsers = 0;
        $top = max(1, $top);

        foreach ($userIds as $userId) {
            $user = $users->get($userId);

            if (!$user) {
                continue;
            }

            $this->service->generateForUser($user, $top);
            $updatedUsers++;
        }

        return $updatedUsers;
    }

    /**
     * Build interaction dataset from analytics events, bookings, and wishlist.
     *
     * @return array{
     *   user_ids: string[],
     *   tour_ids: string[],
     *   ratings: array<int, array{int,int,float}>,
     *   user_tour_scores: array<string, array<string,float>>
     * }
     */
    private function buildInteractionDataset(): array
    {
        $scores = [];
        $now = now();
        $cutoff = $now->copy()->subYear();
        $approvedTours = Tour::approved()->pluck('id')->map(fn ($id) => (string) $id)->all();
        $approvedSet = array_fill_keys($approvedTours, true);

        $events = AnalyticsEvent::query()
            ->whereNotNull('user_id')
            ->where('occurred_at', '>=', $cutoff)
            ->whereIn('event_name', array_keys(RecommendationService::EVENT_WEIGHTS))
            ->get(['user_id', 'entity_type', 'entity_id', 'event_name', 'occurred_at']);

        foreach ($events as $event) {
            if ($event->entity_type !== 'tour' || empty($event->entity_id)) {
                continue;
            }

            $weight = RecommendationService::EVENT_WEIGHTS[$event->event_name] ?? 1.0;
            $days = $event->occurred_at ? $event->occurred_at->diffInDays($now) : 0;
            $tourId = (string) $event->entity_id;
            if (!isset($approvedSet[$tourId])) {
                continue;
            }
            $this->accumulateScore($scores, (string) $event->user_id, $tourId, $weight, $days);
        }

        $bookings = Booking::query()
            ->where('booking_date', '>=', $cutoff)
            ->join('tour_schedules', 'bookings.tour_schedule_id', '=', 'tour_schedules.id')
            ->get([
                'bookings.user_id',
                'tour_schedules.tour_id',
                'bookings.booking_date',
            ]);

        foreach ($bookings as $booking) {
            if (!$booking->user_id || !$booking->tour_id) {
                continue;
            }

            if (!isset($approvedSet[$booking->tour_id])) {
                continue;
            }

            $days = $booking->booking_date ? $booking->booking_date->diffInDays($now) : 0;
            $this->accumulateScore($scores, (string) $booking->user_id, (string) $booking->tour_id, 6.0, $days);
        }

        $wishlists = Wishlist::query()
            ->where('created_at', '>=', $cutoff)
            ->get(['user_id', 'tour_id', 'created_at']);

        foreach ($wishlists as $wishlist) {
            if (!$wishlist->user_id || !$wishlist->tour_id) {
                continue;
            }

            if (!isset($approvedSet[$wishlist->tour_id])) {
                continue;
            }

            $createdAt = $wishlist->created_at ? Carbon::parse($wishlist->created_at) : null;
            $days = $createdAt ? $createdAt->diffInDays($now) : 0;

            $this->accumulateScore($scores, (string) $wishlist->user_id, (string) $wishlist->tour_id, 3.0, $days);
        }

        // Ingest user_activity_logs (aligned to EVENT_WEIGHTS)
        $activityWeights = [
            'tour_view' => RecommendationService::EVENT_WEIGHTS['tour_view'] ?? 1.0,
            'wishlist_add' => RecommendationService::EVENT_WEIGHTS['wishlist_add'] ?? 3.0,
            'cart_add' => RecommendationService::EVENT_WEIGHTS['cart_add'] ?? 4.0,
            'booking_created' => RecommendationService::EVENT_WEIGHTS['booking_created'] ?? 6.0,
            'booking_cancelled' => RecommendationService::EVENT_WEIGHTS['booking_cancelled'] ?? 0.0,
            'review_submitted' => RecommendationService::EVENT_WEIGHTS['review_submitted'] ?? (RecommendationService::EVENT_WEIGHTS['review_submit'] ?? 5.0),
        ];

        $activityLogs = UserActivityLog::query()
            ->where('created_at', '>=', $cutoff)
            ->whereNotNull('user_id')
            ->whereNotNull('tour_id')
            ->whereIn('action', array_keys($activityWeights))
            ->get(['user_id', 'tour_id', 'action', 'created_at']);

        foreach ($activityLogs as $log) {
            $tourId = (string) $log->tour_id;
            if (!isset($approvedSet[$tourId])) {
                continue;
            }
            $weight = $activityWeights[$log->action] ?? 0.0;
            if ($weight <= 0) {
                continue;
            }
            $days = $log->created_at ? $log->created_at->diffInDays($now) : 0;
            $this->accumulateScore($scores, (string) $log->user_id, $tourId, $weight, $days);
        }

        $userIds = array_keys($scores);
        $tourIds = collect($scores)
            ->flatMap(fn ($items) => array_keys($items))
            ->unique()
            ->values()
            ->all();

        $userIndex = array_flip($userIds);
        $tourIndex = array_flip($tourIds);
        $ratings = [];

        foreach ($scores as $userId => $items) {
            if (!isset($userIndex[$userId])) {
                continue;
            }

            $uIdx = $userIndex[$userId];

            foreach ($items as $tourId => $score) {
                if (!isset($tourIndex[$tourId])) {
                    continue;
                }

                if ($score <= 0) {
                    continue;
                }

                $iIdx = $tourIndex[$tourId];
                $ratings[] = [$uIdx, $iIdx, (float) max(0.1, $score)];
            }
        }

        return [
            'user_ids' => array_values($userIds),
            'tour_ids' => array_values($tourIds),
            'ratings' => $ratings,
            'user_tour_scores' => $scores,
        ];
    }

    private function persistCollaborativeEmbeddings(array $userIds, array $userFactors, array $tourIds, array $itemFactors): void
    {
        $now = now();

        foreach ($userIds as $index => $userId) {
            RecommendationEmbedding::updateOrCreate(
                [
                    'entity_type' => 'user_cf',
                    'entity_id' => (string) $userId,
                ],
                [
                    'vector' => $this->roundNumericVector($userFactors[$index]),
                    'extra' => null,
                    'generated_at' => $now,
                ]
            );
        }

        foreach ($tourIds as $index => $tourId) {
            RecommendationEmbedding::updateOrCreate(
                [
                    'entity_type' => 'tour_cf',
                    'entity_id' => (string) $tourId,
                ],
                [
                    'vector' => $this->roundNumericVector($itemFactors[$index]),
                    'extra' => null,
                    'generated_at' => $now,
                ]
            );
        }
    }

    private function persistTourContentEmbeddings(array $tfidfVectors): void
    {
        $now = now();

        foreach ($tfidfVectors as $tourId => $data) {
            $vector = $data['vector'] ?? [];
            if (empty($vector)) {
                continue;
            }

            RecommendationEmbedding::updateOrCreate(
                [
                    'entity_type' => 'tour_tfidf',
                    'entity_id' => (string) $tourId,
                ],
                [
                    'vector' => $this->roundAssociativeVector($vector),
                    'extra' => null,
                    'generated_at' => $now,
                ]
            );
        }
    }

    private function persistUserContentEmbeddings(array $userProfiles): void
    {
        $now = now();
        foreach ($userProfiles as $userId => $profile) {
            $vector = $profile['vector'] ?? [];
            if (empty($vector)) {
                continue;
            }

            RecommendationEmbedding::updateOrCreate(
                [
                    'entity_type' => 'user_tfidf',
                    'entity_id' => (string) $userId,
                ],
                [
                    'vector' => $this->roundAssociativeVector($vector),
                    'extra' => ['norm' => $profile['norm'] ?? 1.0],
                    'generated_at' => $now,
                ]
            );
        }
    }

    private function refreshFeatures(array $tourIds): void
    {
        if (empty($tourIds)) {
            return;
        }

        $tours = Tour::query()
            ->whereIn('id', $tourIds)
            ->get([
                'id',
                'type',
                'duration',
                'base_price',
                'child_age_limit',
                'requires_passport',
                'requires_visa',
            ])
            ->keyBy(fn ($tour) => (string) $tour->id);

        $ratingStats = DB::table('reviews')
            ->join('bookings', 'reviews.booking_id', '=', 'bookings.id')
            ->join('tour_schedules', 'bookings.tour_schedule_id', '=', 'tour_schedules.id')
            ->select(
                'tour_schedules.tour_id as tour_id',
                DB::raw('AVG(reviews.rating) as avg_rating'),
                DB::raw('COUNT(reviews.id) as review_count')
            )
            ->whereIn('tour_schedules.tour_id', $tourIds)
            ->groupBy('tour_schedules.tour_id')
            ->get()
            ->keyBy('tour_id');

        foreach ($tourIds as $tourId) {
            $tour = $tours->get($tourId);
            if (!$tour) {
                continue;
            }

            $rating = $ratingStats->get($tourId);

            $features = [
                'type' => $tour->type,
                'duration' => (int) $tour->duration,
                'base_price' => (float) $tour->base_price,
                'child_age_limit' => (int) $tour->child_age_limit,
                'requires_passport' => (bool) $tour->requires_passport,
                'requires_visa' => (bool) $tour->requires_visa,
                'avg_rating' => $rating ? (float) $rating->avg_rating : null,
                'rating_count' => $rating ? (int) $rating->review_count : 0,
            ];

            RecommendationFeature::updateOrCreate(
                ['tour_id' => (string) $tourId],
                [
                    'features' => $features,
                    'calculated_at' => now(),
                ]
            );
        }
    }

    private function refreshPopularity(array $tourIds): void
    {
        if (empty($tourIds)) {
            return;
        }

        $bookingCounts = Booking::query()
            ->join('tour_schedules', 'bookings.tour_schedule_id', '=', 'tour_schedules.id')
            ->select('tour_schedules.tour_id', DB::raw('COUNT(bookings.id) as count'))
            ->whereIn('tour_schedules.tour_id', $tourIds)
            ->groupBy('tour_schedules.tour_id')
            ->pluck('count', 'tour_schedules.tour_id');

        $wishlistCounts = Wishlist::query()
            ->select('tour_id', DB::raw('COUNT(*) as count'))
            ->whereIn('tour_id', $tourIds)
            ->groupBy('tour_id')
            ->pluck('count', 'tour_id');

        $viewCounts = AnalyticsEvent::query()
            ->select('entity_id', DB::raw('COUNT(*) as count'))
            ->where('event_name', 'tour_view')
            ->whereIn('entity_id', $tourIds)
            ->groupBy('entity_id')
            ->pluck('count', 'entity_id');

        foreach ($tourIds as $tourId) {
            $bookings = (int) ($bookingCounts[$tourId] ?? 0);
            $wishlists = (int) ($wishlistCounts[$tourId] ?? 0);
            $views = (int) ($viewCounts[$tourId] ?? 0);

            $score = ($bookings * 3) + ($wishlists * 2) + ($views * 0.5);

            RecommendationPopularity::updateOrCreate(
                ['tour_id' => $tourId, 'window' => 'overall'],
                [
                    'bookings_count' => $bookings,
                    'wishlist_count' => $wishlists,
                    'views_count' => $views,
                    'score' => $score,
                ]
            );
        }
    }

    private function buildTfidfVectors(array $tourIds): array
    {
        if (empty($tourIds)) {
            return [];
        }

        $tours = Tour::query()
            ->whereIn('id', $tourIds)
            ->get(['id', 'title', 'description', 'destination', 'policy', 'itinerary', 'tags', 'type']);

        $documents = [];
        $termDocFrequency = [];

        foreach ($tours as $tour) {
            $terms = $this->extractTerms($tour);
            if (empty($terms)) {
                continue;
            }

            $documents[(string) $tour->id] = $terms;

            $uniqueTerms = array_unique($terms);
            foreach ($uniqueTerms as $term) {
                $termDocFrequency[$term] = ($termDocFrequency[$term] ?? 0) + 1;
            }
        }

        $docCount = count($documents);
        if ($docCount === 0) {
            return [];
        }

        $idf = [];
        foreach ($termDocFrequency as $term => $df) {
            $idf[$term] = log(($docCount + 1) / ($df + 1)) + 1;
        }

        $vectors = [];

        foreach ($documents as $tourId => $terms) {
            $tf = [];
            foreach ($terms as $term) {
                $tf[$term] = ($tf[$term] ?? 0) + 1;
            }

            $termCount = array_sum($tf);
            if ($termCount <= 0) {
                continue;
            }

            $vector = [];
            foreach ($tf as $term => $count) {
                $tfWeight = $count / $termCount;
                $vector[$term] = $tfWeight * ($idf[$term] ?? 0);
            }

            $normalized = $this->normalizeVector($vector);
            $vectors[$tourId] = [
                'vector' => $normalized['vector'],
                'norm' => $normalized['norm'],
            ];
        }

        return $vectors;
    }

    private function extractTerms(Tour $tour): array
    {
        $terms = [];
        $fields = [
            $tour->title ?? '',
            $tour->description ?? '',
            $tour->destination ?? '',
            $tour->policy ?? '',
        ];

        if (is_array($tour->itinerary)) {
            $itinerary = $tour->itinerary;
            $items = [];
            array_walk_recursive($itinerary, function ($value) use (&$items) {
                if (is_string($value)) {
                    $items[] = $value;
                }
            });
            $fields[] = implode(' ', array_filter($items));
        } elseif (is_string($tour->itinerary)) {
            $fields[] = $tour->itinerary;
        }

        if (is_array($tour->tags)) {
            $fields[] = implode(' ', array_filter($tour->tags));
        }

        foreach ($fields as $field) {
            $terms = array_merge($terms, $this->tokenize((string) $field));
        }

        return $terms;
    }

    private function tokenize(string $text): array
    {
        $text = mb_strtolower($text, 'UTF-8');
        $text = preg_replace('/[^\p{L}\p{N}\s]/u', ' ', $text);
        $tokens = preg_split('/\s+/', $text, -1, PREG_SPLIT_NO_EMPTY);

        return array_values(array_filter($tokens, function ($token) {
            return mb_strlen($token, 'UTF-8') >= self::TOKEN_MIN_LENGTH;
        }));
    }

    private function buildUserContentVector(array $tourScores, array $tfidfVectors): array
    {
        $vector = [];

        foreach ($tourScores as $tourId => $score) {
            if ($score <= 0 || !isset($tfidfVectors[$tourId])) {
                continue;
            }

            foreach ($tfidfVectors[$tourId]['vector'] as $term => $weight) {
                $vector[$term] = ($vector[$term] ?? 0) + ($score * $weight);
            }
        }

        return $this->normalizeVector($vector);
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

            $sum += $weight * $larger[$term];
        }

        return $sum;
    }

    private function roundNumericVector(array $vector): array
    {
        $result = [];

        foreach ($vector as $value) {
            $value = (float) $value;
            if (!is_finite($value)) {
                $value = 0.0;
            }

            $result[] = round($value, 6);
        }

        return $result;
    }

    private function roundAssociativeVector(array $vector): array
    {
        $result = [];

        foreach ($vector as $key => $value) {
            $value = (float) $value;
            if (!is_finite($value)) {
                $value = 0.0;
            }

            $result[$key] = round($value, 6);
        }

        return $result;
    }

    private function normalizeVector(array $vector): array
    {
        $norm = 0.0;
        foreach ($vector as $value) {
            $norm += $value * $value;
        }
        $norm = sqrt($norm);

        if ($norm <= 0) {
            return ['vector' => [], 'norm' => 0.0];
        }

        foreach ($vector as $term => $value) {
            $vector[$term] = $value / $norm;
        }

        return ['vector' => $vector, 'norm' => 1.0];
    }
    private function accumulateScore(array &$scores, string $userId, string $tourId, float $weight, int $daysAgo): void
    {
        if ($weight === 0.0) {
            return;
        }

        $decay = $this->service->decayFactor($daysAgo);
        $score = $weight * $decay;

        if ($score === 0.0) {
            return;
        }

        if (!isset($scores[$userId])) {
            $scores[$userId] = [];
        }

        $scores[$userId][$tourId] = ($scores[$userId][$tourId] ?? 0) + $score;
    }

    /**
     * @return array<int, array<int, float>>
     */
    private function initializeFactors(int $rows, int $factors): array
    {
        $matrix = [];

        for ($i = 0; $i < $rows; $i++) {
            $vector = [];

            for ($k = 0; $k < $factors; $k++) {
                $vector[$k] = (mt_rand() / mt_getrandmax()) / $factors;
            }

            $matrix[$i] = $vector;
        }

        return $matrix;
    }

    private function dotProduct(array $a, array $b): float
    {
        $sum = 0.0;
        $length = min(count($a), count($b));

        for ($i = 0; $i < $length; $i++) {
            $sum += $a[$i] * $b[$i];
        }

        return $sum;
    }
}






