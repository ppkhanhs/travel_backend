<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\WishlistItemResource;
use App\Models\Tour;
use App\Models\UserActivityLog;
use App\Models\Wishlist;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class WishlistController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $items = Wishlist::where('user_id', $request->user()->id)
            ->with([
                'tour.packages',
                'tour.schedules' => fn ($q) => $q->orderBy('start_date'),
            ])
            ->orderByDesc('created_at')
            ->get();

        $this->attachRatingStats($items->pluck('tour_id')->filter()->all(), $items->pluck('tour')->filter());

        return response()->json([
            'items' => WishlistItemResource::collection($items),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'tour_id' => ['required', 'uuid', 'exists:tours,id'],
        ]);

        $userId = $request->user()->id;
        $tourId = $data['tour_id'];

        $tour = Tour::findOrFail($tourId);

        $wishlist = Wishlist::firstOrCreate([
            'user_id' => $userId,
            'tour_id' => $tourId,
        ]);

        $this->logUserActivity($userId, $tourId, 'wishlist');

        return response()->json([
            'message' => 'Tour đã được thêm vào danh sách yêu thích.',
            'item' => new WishlistItemResource($wishlist->load('tour')),
        ], 201);
    }

    public function destroy(Request $request, string $id): JsonResponse
    {
        $wishlist = Wishlist::where('user_id', $request->user()->id)->findOrFail($id);
        $wishlist->delete();

        return response()->json([
            'message' => 'Đã xóa tour khỏi danh sách yêu thích.',
        ]);
    }

    public function compare(Request $request): JsonResponse
    {
        $data = $request->validate([
            'tour_ids' => ['required', 'array', 'min:1', 'max:2'],
            'tour_ids.*' => ['uuid'],
        ]);

        $userId = $request->user()->id;
        $tourIds = $data['tour_ids'];

        $wishlistedIds = Wishlist::where('user_id', $userId)
            ->whereIn('tour_id', $tourIds)
            ->pluck('tour_id')
            ->all();

        if (count($wishlistedIds) !== count($tourIds)) {
            throw ValidationException::withMessages([
                'tour_ids' => ['Chỉ có thể so sánh các tour trong danh sách yêu thích.'],
            ]);
        }

        $tours = Tour::with([
                'partner.user',
                'categories',
                'packages',
                'schedules' => fn ($q) => $q->orderBy('start_date'),
            ])
            ->whereIn('id', $tourIds)
            ->get()
            ->map(function ($tour) {
                $tour->setAttribute('available', $tour->status === 'approved');
                return $tour;
            });

        $this->attachRatingStats($tourIds, $tours);

        return response()->json([
            'tours' => $tours,
        ]);
    }

    private function logUserActivity(string $userId, string $tourId, string $action): void
    {
        try {
            UserActivityLog::create([
                'user_id' => $userId,
                'tour_id' => $tourId,
                'action' => $action,
                'created_at' => now(),
            ]);
        } catch (\Throwable $e) {
            // ignore logging failure
        }
    }

    private function attachRatingStats(array $tourIds, $tours): void
    {
        if (empty($tourIds) || $tours->isEmpty()) {
            return;
        }

        $stats = DB::table('reviews')
            ->join('bookings', 'reviews.booking_id', '=', 'bookings.id')
            ->join('tour_schedules', 'bookings.tour_schedule_id', '=', 'tour_schedules.id')
            ->select(
                'tour_schedules.tour_id',
                DB::raw('AVG(reviews.rating) as rating_average'),
                DB::raw('COUNT(reviews.id) as rating_count')
            )
            ->whereIn('tour_schedules.tour_id', $tourIds)
            ->groupBy('tour_schedules.tour_id')
            ->get()
            ->keyBy('tour_id');

        $tours->each(function ($tour) use ($stats) {
            if (!$tour) {
                return;
            }

            $stat = $stats->get($tour->id);
            $tour->setAttribute('rating_average', $stat ? (float) $stat->rating_average : 0);
            $tour->setAttribute('rating_count', $stat ? (int) $stat->rating_count : 0);
        });
    }
}
