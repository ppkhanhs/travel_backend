<?php

namespace App\Http\Controllers\Api\Partner;

use App\Http\Controllers\Controller;
use App\Models\Booking;
use App\Models\Promotion;
use App\Models\Tour;
use App\Models\TourSchedule;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

class DashboardController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $partner = $this->getAuthenticatedPartner();
        if (!$partner) {
            return response()->json(['message' => 'Partner account is not approved or unavailable.'], 403);
        }

        $rangeDays = max(1, min($request->integer('range', 30), 180));
        $since = Carbon::now()->subDays($rangeDays);

        $toursQuery = Tour::query()->where('partner_id', $partner->id);
        $toursStats = [
            'total' => (clone $toursQuery)->count(),
            'approved' => (clone $toursQuery)->where('status', 'approved')->count(),
            'pending' => (clone $toursQuery)->where('status', 'pending')->count(),
            'rejected' => (clone $toursQuery)->where('status', 'rejected')->count(),
        ];

        $activePromotions = Promotion::query()
            ->where('partner_id', $partner->id)
            ->where('is_active', true)
            ->count();

        $bookingsBase = Booking::query()
            ->select('bookings.*')
            ->join('tour_schedules', 'bookings.tour_schedule_id', '=', 'tour_schedules.id')
            ->join('tours', 'tour_schedules.tour_id', '=', 'tours.id')
            ->where('tours.partner_id', $partner->id);

        $bookingsTotal = (clone $bookingsBase)->count();

        $bookingsByStatus = (clone $bookingsBase)
            ->select('bookings.status', DB::raw('COUNT(*) as total'))
            ->groupBy('bookings.status')
            ->pluck('total', 'status');

        $rangeBookings = (clone $bookingsBase)->where('bookings.booking_date', '>=', $since);
        $rangePaid = (clone $rangeBookings)->where('bookings.payment_status', 'paid');

        $rangeMetrics = [
            'bookings' => $rangeBookings->count(),
            'paid_bookings' => (clone $rangePaid)->count(),
            'revenue' => (float) (clone $rangePaid)->sum('bookings.total_price'),
        ];

        $overallRevenue = (float) (clone $bookingsBase)
            ->where('bookings.payment_status', 'paid')
            ->sum('bookings.total_price');

        $dailyRevenue = (clone $rangePaid)
            ->selectRaw('DATE(bookings.booking_date) as date')
            ->selectRaw('SUM(bookings.total_price) as revenue')
            ->selectRaw('COUNT(bookings.id) as bookings')
            ->groupBy('date')
            ->orderBy('date')
            ->get()
            ->map(function ($row) {
                return [
                    'date' => Carbon::parse($row->date)->toDateString(),
                    'revenue' => (float) $row->revenue,
                    'bookings' => (int) $row->bookings,
                ];
            });

        $upcomingSchedules = TourSchedule::query()
            ->with(['tour:id,title'])
            ->whereDate('start_date', '>=', Carbon::now()->toDateString())
            ->whereHas('tour', fn ($query) => $query->where('partner_id', $partner->id))
            ->leftJoin('bookings', function ($join) {
                $join->on('bookings.tour_schedule_id', '=', 'tour_schedules.id')
                    ->whereIn('bookings.status', ['pending', 'confirmed']);
            })
            ->select(
                'tour_schedules.*',
                DB::raw('COALESCE(SUM(bookings.total_adults + bookings.total_children), 0) as passengers')
            )
            ->groupBy('tour_schedules.id')
            ->orderBy('tour_schedules.start_date')
            ->limit(5)
            ->get()
            ->map(function (TourSchedule $schedule) {
                return [
                    'id' => $schedule->id,
                    'tour_title' => $schedule->tour?->title,
                    'start_date' => optional($schedule->start_date)->toDateString(),
                    'end_date' => optional($schedule->end_date)->toDateString(),
                    'seats_total' => (int) $schedule->seats_total,
                    'seats_available' => (int) $schedule->seats_available,
                    'booked_passengers' => (int) ($schedule->passengers ?? 0),
                ];
            });

        $recentBookings = Booking::with([
                'tourSchedule.tour:id,title,destination',
                'user:id,name',
            ])
            ->whereHas('tourSchedule.tour', fn ($query) => $query->where('partner_id', $partner->id))
            ->orderByDesc('booking_date')
            ->limit(5)
            ->get()
            ->map(function (Booking $booking) {
                return [
                    'id' => $booking->id,
                    'status' => $booking->status,
                    'payment_status' => $booking->payment_status,
                    'total_price' => (float) $booking->total_price,
                    'booking_date' => optional($booking->booking_date)->toIso8601String(),
                    'customer_name' => $booking->contact_name ?: $booking->user?->name,
                    'tour' => [
                        'id' => $booking->tourSchedule?->tour?->id,
                        'title' => $booking->tourSchedule?->tour?->title,
                        'destination' => $booking->tourSchedule?->tour?->destination,
                    ],
                ];
            });

        return response()->json([
            'range_days' => $rangeDays,
            'totals' => [
                'tours' => $toursStats,
                'active_promotions' => $activePromotions,
                'bookings' => $bookingsTotal,
            ],
            'bookings' => [
                'by_status' => $bookingsByStatus,
                'range' => $rangeMetrics,
            ],
            'revenue' => [
                'overall' => $overallRevenue,
                'range' => $rangeMetrics['revenue'],
                'daily' => $dailyRevenue,
            ],
            'upcoming_departures' => $upcomingSchedules,
            'recent_bookings' => $recentBookings,
        ]);
    }

    private function getAuthenticatedPartner(): ?object
    {
        $userId = Auth::id();
        if (!$userId) {
            return null;
        }

        $partner = DB::table('partners')
            ->where('user_id', $userId)
            ->first();

        if (!$partner || $partner->status !== 'approved') {
            return null;
        }

        return $partner;
    }
}
