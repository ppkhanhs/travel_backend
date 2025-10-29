<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Tour;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class TourController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $query = Tour::with([
            'partner.user',
            'categories',
            'schedules' => function ($q) {
                $q->orderBy('start_date');
            },
            'packages' => function ($q) {
                $q->where('is_active', true)->orderBy('adult_price');
            },
            'cancellationPolicies' => function ($q) {
                $q->orderByDesc('days_before');
            },
        ]);

        if (!$request->filled('status') || $request->status === 'approved') {
            $query->where('status', 'approved');
        } elseif ($request->status !== 'all') {
            $query->where('status', $request->status);
        }

        if ($request->filled('partner_id')) {
            $query->where('partner_id', $request->partner_id);
        }

        if ($request->filled('destination')) {
            $query->whereRaw('destination ILIKE ?', ['%' . $this->escapeLike($request->destination) . '%']);
        }

        if ($request->filled('search')) {
            $term = '%' . $this->escapeLike($request->search) . '%';
            $query->where(function ($q) use ($term) {
                $q->whereRaw('title ILIKE ?', [$term])
                    ->orWhereRaw('destination ILIKE ?', [$term]);
            });
        }

        if ($request->filled('category_id')) {
            $categoryIds = (array) $request->category_id;
            $query->whereHas('categories', function ($q) use ($categoryIds) {
                $q->whereIn('categories.id', $categoryIds);
            });
        }

        if ($request->filled('tags')) {
            $tags = array_filter((array) $request->tags);
            if (!empty($tags)) {
                $query->where(function ($q) use ($tags) {
                    foreach ($tags as $index => $tag) {
                        if ($index === 0) {
                            $q->whereRaw('? = ANY(tags)', [$tag]);
                        } else {
                            $q->orWhereRaw('? = ANY(tags)', [$tag]);
                        }
                    }
                });
            }
        }

        if ($request->filled('price_min')) {
            $min = $request->input('price_min');
            $query->where(function ($q) use ($min) {
                $q->where('base_price', '>=', $min)
                    ->orWhereHas('schedules', function ($sq) use ($min) {
                        $sq->where('season_price', '>=', $min);
                    });
            });
        }

        if ($request->filled('price_max')) {
            $max = $request->input('price_max');
            $query->where(function ($q) use ($max) {
                $q->where('base_price', '<=', $max)
                    ->orWhereHas('schedules', function ($sq) use ($max) {
                        $sq->where('season_price', '<=', $max);
                    });
            });
        }

        if ($request->filled('duration_min')) {
            $query->where('duration', '>=', $request->integer('duration_min'));
        }

        if ($request->filled('duration_max')) {
            $query->where('duration', '<=', $request->integer('duration_max'));
        }

        if ($request->filled('start_date')) {
            try {
                $date = Carbon::parse($request->start_date)->toDateString();
                $query->whereHas('schedules', function ($sq) use ($date) {
                    $sq->where('start_date', '>=', $date);
                });
            } catch (\Exception $e) {
                // ignore invalid date format
            }
        }

        $query->leftJoinSub($this->reviewStatsSubquery(), 'review_stats', function ($join) {
            $join->on('review_stats.tour_id', '=', 'tours.id');
        });

        $query->select(
            'tours.*',
            DB::raw('COALESCE(review_stats.rating_average, 0) as rating_average'),
            DB::raw('COALESCE(review_stats.rating_count, 0) as rating_count')
        );

        if ($request->filled('sort')) {
            switch ($request->sort) {
                case 'price_asc':
                    $query->orderBy('tours.base_price');
                    break;
                case 'price_desc':
                    $query->orderByDesc('tours.base_price');
                    break;
                case 'newest':
                    $query->orderByDesc('tours.created_at');
                    break;
                default:
                    $query->orderBy('tours.title');
            }
        } else {
            $query->orderBy('tours.title');
        }

        $tours = $query->paginate($request->integer('per_page', 12));

        return response()->json($tours);
    }

    public function show(string $id): JsonResponse
    {
        $tour = Tour::approved()
            ->with([
                'partner.user',
                'categories',
                'schedules' => function ($q) {
                    $q->orderBy('start_date');
                },
                'packages' => function ($q) {
                    $q->where('is_active', true)->orderBy('adult_price');
                },
                'cancellationPolicies' => function ($q) {
                    $q->orderByDesc('days_before');
                },
            ])
            ->findOrFail($id);

        $stats = $this->reviewStatsSubquery()
            ->where('tour_schedules.tour_id', $tour->id)
            ->first();

        $tour->setAttribute('rating_average', (float) ($stats->rating_average ?? 0));
        $tour->setAttribute('rating_count', (int) ($stats->rating_count ?? 0));

        return response()->json($tour);
    }

    public function trending(Request $request): JsonResponse
    {
        $limit = $request->integer('limit', 8);
        $days = $request->integer('days', 30);
        $since = Carbon::now()->subDays($days);

        $bookingStats = DB::table('bookings')
            ->join('tour_schedules', 'bookings.tour_schedule_id', '=', 'tour_schedules.id')
            ->select('tour_schedules.tour_id', DB::raw('COUNT(bookings.id) as bookings_count'))
            ->where('bookings.booking_date', '>=', $since)
            ->where(function ($q) {
                $q->whereNull('bookings.status')
                    ->orWhere('bookings.status', '!=', 'cancelled');
            })
            ->groupBy('tour_schedules.tour_id');

        $reviewStats = $this->reviewStatsSubquery();

        $tours = Tour::approved()
            ->with([
                'partner.user',
                'categories',
                'packages' => function ($q) {
                    $q->where('is_active', true)->orderBy('adult_price');
                },
                'cancellationPolicies' => function ($q) {
                    $q->orderByDesc('days_before');
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

        return response()->json($tours);
    }

    public function suggestions(Request $request): JsonResponse
    {
        $keyword = trim((string) $request->get('keyword', ''));

        if ($keyword === '') {
            return response()->json(['suggestions' => []]);
        }

        $term = '%' . $this->escapeLike($keyword) . '%';

        $suggestions = Tour::approved()
            ->select('id', 'title', 'destination')
            ->where(function ($q) use ($term) {
                $q->whereRaw('title ILIKE ?', [$term])
                    ->orWhereRaw('destination ILIKE ?', [$term]);
            })
            ->limit(10)
            ->get();

        return response()->json(['suggestions' => $suggestions]);
    }

    private function escapeLike(string $value): string
    {
        return str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $value);
    }

    private function reviewStatsSubquery()
    {
        return DB::table('reviews')
            ->join('bookings', 'reviews.booking_id', '=', 'bookings.id')
            ->join('tour_schedules', 'bookings.tour_schedule_id', '=', 'tour_schedules.id')
            ->select(
                'tour_schedules.tour_id',
                DB::raw('AVG(reviews.rating)::float as rating_average'),
                DB::raw('COUNT(reviews.id) as rating_count')
            )
            ->groupBy('tour_schedules.tour_id');
    }
}


