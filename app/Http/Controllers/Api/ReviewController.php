<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Booking;
use App\Models\Review;
use App\Models\Tour;
use App\Services\UserActivityLogger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ReviewController extends Controller
{
    public function __construct(private UserActivityLogger $activityLogger)
    {
    }

    public function index(Request $request, string $tourId): JsonResponse
    {
        $tour = Tour::approved()->findOrFail($tourId);

        $reviews = Review::with([
                'user:id,name',
                'booking:id,tour_schedule_id',
                'booking.tourSchedule:id,tour_id,start_date',
            ])
            ->whereHas('booking.tourSchedule', static function ($query) use ($tourId) {
                $query->where('tour_id', $tourId);
            })
            ->orderByDesc('created_at')
            ->paginate($request->integer('per_page', 10));

        $stats = $this->buildStatsQuery()
            ->where('tour_schedules.tour_id', $tourId)
            ->first();

        return response()->json([
            'reviews' => $reviews,
            'rating' => [
                'average' => (float) ($stats->rating_average ?? 0),
                'count' => (int) ($stats->rating_count ?? 0),
            ],
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'booking_id' => 'required|uuid',
            'rating' => 'required|integer|min:1|max:5',
            'comment' => 'nullable|string|max:2000',
        ]);

        $booking = Booking::with(['review', 'tourSchedule'])
            ->where('id', $data['booking_id'])
            ->where('user_id', $request->user()->id)
            ->firstOrFail();

        if ($booking->status !== 'completed') {
            throw ValidationException::withMessages([
                'booking_id' => ['Chỉ những booking đã hoàn thành mới được đánh giá.'],
            ]);
        }

        if ($booking->review) {
            throw ValidationException::withMessages([
                'booking_id' => ['Booking này đã có đánh giá. Bạn có thể cập nhật lại bằng API chỉnh sửa.'],
            ]);
        }

        if (!$booking->tourSchedule) {
            throw ValidationException::withMessages([
                'booking_id' => ['Booking không chứa thông tin tour hợp lệ.'],
            ]);
        }

        $review = Review::create([
            'booking_id' => $booking->id,
            'user_id' => $request->user()->id,
            'rating' => $data['rating'],
            'comment' => $data['comment'] ?? null,
        ]);

        $review->load([
            'user:id,name',
            'booking:id,tour_schedule_id',
            'booking.tourSchedule:id,tour_id,start_date',
        ]);

        $tourId = $booking->tourSchedule?->tour_id;
        if ($tourId) {
            $this->activityLogger->log($request->user(), (string) $tourId, 'review_submitted');
        }

        return response()->json([
            'message' => 'Đánh giá đã được ghi nhận.',
            'review' => $review,
        ], 201);
    }

    public function update(Request $request, string $id): JsonResponse
    {
        $review = Review::with(['booking'])->findOrFail($id);

        if ($review->user_id !== $request->user()->id) {
            abort(403, 'Bạn không có quyền chỉnh sửa đánh giá này.');
        }

        $data = $request->validate([
            'rating' => 'sometimes|required|integer|min:1|max:5',
            'comment' => 'sometimes|nullable|string|max:2000',
        ]);

        if (array_key_exists('rating', $data)) {
            $review->rating = $data['rating'];
        }

        if (array_key_exists('comment', $data)) {
            $review->comment = $data['comment'];
        }

        $review->save();

        $review->load([
            'user:id,name',
            'booking:id,tour_schedule_id',
            'booking.tourSchedule:id,tour_id,start_date',
        ]);

        return response()->json([
            'message' => 'Đánh giá đã được cập nhật.',
            'review' => $review,
        ]);
    }

    public function destroy(Request $request, string $id): JsonResponse
    {
        $review = Review::findOrFail($id);

        if ($review->user_id !== $request->user()->id) {
            abort(403, 'Bạn không có quyền xóa đánh giá này.');
        }

        $review->delete();

        return response()->json([
            'message' => 'Đánh giá đã được xóa.',
        ]);
    }

    private function buildStatsQuery()
    {
        return DB::table('reviews')
            ->join('bookings', 'reviews.booking_id', '=', 'bookings.id')
            ->join('tour_schedules', 'bookings.tour_schedule_id', '=', 'tour_schedules.id')
            ->select(
                DB::raw('AVG(reviews.rating)::float as rating_average'),
                DB::raw('COUNT(reviews.id) as rating_count'),
                'tour_schedules.tour_id'
            )
            ->groupBy('tour_schedules.tour_id');
    }
}
