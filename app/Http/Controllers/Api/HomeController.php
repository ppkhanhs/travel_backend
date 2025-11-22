<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\RecommendationResource;
use App\Models\Category;
use App\Models\Promotion;
use App\Models\Tour;
use App\Services\AutoPromotionService;
use App\Services\RecommendationService;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Auth;

class HomeController extends Controller
{
    public function __construct(
        private AutoPromotionService $autoPromotions,
        private RecommendationService $recommendations
    )
    {
    }

    public function index(Request $request): JsonResponse
    {
        $categoriesLimit = $request->integer('categories_limit', 6);
        $promotionsLimit = $request->integer('promotions_limit', 5);
        $trendingLimit = $request->integer('trending_limit', 8);

        $categories = $this->getHighlightCategories($categoriesLimit);
        $promotions = Promotion::active()
            ->where('auto_apply', false)
            ->orderBy('valid_from')
            ->limit($promotionsLimit)
            ->get();
        $trending = $this->getTrendingTours($trendingLimit);
        $recommendedBlock = $this->buildRecommendationsBlock($request);

        return response()->json([
            'categories' => $categories,
            'promotions' => $promotions,
            'trending' => $trending,
            'recommended' => $recommendedBlock['items'],
            'recommendations_meta' => $recommendedBlock['meta'],
        ]);
    }

    public function highlightCategories(Request $request): JsonResponse
    {
        $limit = $request->integer('limit', 6);

        return response()->json($this->getHighlightCategories($limit));
    }

    private function getHighlightCategories(int $limit)
    {
        return Category::select('categories.id', 'categories.name', 'categories.slug')
            ->withCount(['tours as tours_count' => function ($query) {
                $query->where('status', 'approved');
            }])
            ->orderByDesc('tours_count')
            ->orderBy('name')
            ->limit($limit)
            ->get();
    }

    private function getTrendingTours(int $limit)
    {
        $since = Carbon::now()->subDays(30);

        $bookingStats = DB::table('bookings')
            ->join('tour_schedules', 'bookings.tour_schedule_id', '=', 'tour_schedules.id')
            ->select('tour_schedules.tour_id', DB::raw('COUNT(bookings.id) as bookings_count'))
            ->where('bookings.booking_date', '>=', $since)
            ->where(function ($q) {
                $q->whereNull('bookings.status')
                    ->orWhere('bookings.status', '!=', 'cancelled');
            })
            ->groupBy('tour_schedules.tour_id');

        $reviewStats = DB::table('reviews')
            ->join('bookings', 'reviews.booking_id', '=', 'bookings.id')
            ->join('tour_schedules', 'bookings.tour_schedule_id', '=', 'tour_schedules.id')
            ->select(
                'tour_schedules.tour_id',
                DB::raw('AVG(reviews.rating)::float as rating_average'),
                DB::raw('COUNT(reviews.id) as rating_count')
            )
            ->groupBy('tour_schedules.tour_id');

        $tours = Tour::approved()
            ->with([
                'partner.user',
                'categories',
                'schedules' => function ($query) {
                    $query->orderBy('start_date');
                },
                'packages' => function ($query) {
                    $query->where('is_active', true)->orderBy('adult_price');
                },
            ]) 
            ->leftJoinSub($bookingStats, 'booking_stats', function ($join) {
                $join->on('booking_stats.tour_id', '=', 'tours.id');
            })
            ->leftJoinSub($reviewStats, 'review_stats', function ($join) {
                $join->on('review_stats.tour_id', '=', 'tours.id');
            })
            ->select(
                'tours.*',
                DB::raw('COALESCE(booking_stats.bookings_count, 0) as bookings_count'),
                DB::raw('COALESCE(review_stats.rating_average, 0) as rating_average'),
                DB::raw('COALESCE(review_stats.rating_count, 0) as rating_count')
            )
            ->orderByDesc('bookings_count')
            ->orderByDesc('tours.created_at')
            ->limit($limit)
            ->get();

        $this->autoPromotions->attachToTours($tours);

        return $tours;
    }

    private function buildRecommendationsBlock(Request $request): array
    {
        $user = $this->resolveOptionalUser($request);
        $meta = [
            'count' => 0,
            'generated_at' => null,
            'has_personalized_signals' => false,
            'personalized_results' => false,
        ];

        if (!$user) {
            return [
                'items' => [],
                'meta' => $meta,
            ];
        }

        $limit = max(1, min(20, (int) $request->integer('recommended_limit', 8)));

        $recommendations = $this->recommendations->getRecommendations($user, $limit);
        $recommendedTours = $recommendations
            ->pluck('tour')
            ->filter(function ($tour) {
                return $tour instanceof Tour;
            });

        if ($recommendedTours->isNotEmpty()) {
            $this->autoPromotions->attachToTours($recommendedTours);
        }

        $items = RecommendationResource::collection($recommendations)->toArray($request);

        $hasSignals = $this->recommendations->hasPersonalizationSignals($user);
        $meta = [
            'count' => $recommendations->count(),
            'generated_at' => optional($user->recommendation)->generated_at
                ? $user->recommendation->generated_at->toIso8601String()
                : null,
            'has_personalized_signals' => $hasSignals,
            'personalized_results' => $recommendations->count() > 0 && $hasSignals,
        ];

        return [
            'items' => $items,
            'meta' => $meta,
        ];
    }

    private function resolveOptionalUser(Request $request): ?\App\Models\User
    {
        $user = $request->user();

        if ($user) {
            return $user;
        }

        $guard = Auth::guard('sanctum');

        if (method_exists($guard, 'setRequest')) {
            $guard->setRequest($request);
        }

        return $guard->user();
    }
}
