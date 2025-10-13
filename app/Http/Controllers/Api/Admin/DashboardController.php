<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\Booking;
use App\Models\Partner;
use App\Models\Tour;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;

class DashboardController extends Controller
{
    public function __invoke(): JsonResponse
    {
        $now = Carbon::now();
        $weekAgo = $now->copy()->subDays(7);
        $monthAgo = $now->copy()->subDays(30);

        $totalUsers = User::count();
        $newUsers = User::where('created_at', '>=', $weekAgo)->count();

        $totalPartners = Partner::count();
        $pendingPartners = Partner::where('status', 'pending')->count();

        $totalTours = Tour::count();
        $activeTours = Tour::where('status', 'approved')->count();

        $totalBookings = Booking::count();
        $bookingsThisMonth = Booking::where('created_at', '>=', $monthAgo)->count();

        $topPartners = Partner::select('partners.id', 'partners.company_name')
            ->withCount(['tours'])
            ->orderByDesc('tours_count')
            ->limit(5)
            ->get();

        $stats = [
            'users' => [
                'total' => $totalUsers,
                'new_last_7_days' => $newUsers,
            ],
            'partners' => [
                'total' => $totalPartners,
                'pending' => $pendingPartners,
            ],
            'tours' => [
                'total' => $totalTours,
                'active' => $activeTours,
            ],
            'bookings' => [
                'total' => $totalBookings,
                'last_30_days' => $bookingsThisMonth,
            ],
            'top_partners' => $topPartners,
        ];

        return response()->json($stats);
    }
}
