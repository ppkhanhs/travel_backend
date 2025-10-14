<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Category;
use App\Models\Promotion;
use App\Models\Tour;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class HomeController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $categoriesLimit = $request->integer('categories_limit', 6);
        $promotionsLimit = $request->integer('promotions_limit', 5);
        $trendingLimit = $request->integer('trending_limit', 8);

        $categories = $this->getHighlightCategories($categoriesLimit);
        $promotions = Promotion::active()
            ->orderBy('valid_from')
            ->limit($promotionsLimit)
            ->get();
        $trending = $this->getTrendingTours($trendingLimit);

        return response()->json([
            'categories' => $categories,
            'promotions' => $promotions,
            'trending' => $trending,
            'recommended' => [], // personalize recommendations will be added later
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

        return Tour::approved()
            ->with(['partner.user', 'categories', 'schedules' => function ($query) {
                $query->orderBy('start_date');
            }])
            ->leftJoinSub($bookingStats, 'booking_stats', function ($join) {
                $join->on('booking_stats.tour_id', '=', 'tours.id');
            })
            ->select('tours.*', DB::raw('COALESCE(booking_stats.bookings_count, 0) as bookings_count'))
            ->orderByDesc('bookings_count')
            ->orderByDesc('tours.created_at')
            ->limit($limit)
            ->get();
    }
}

