<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class ReportController extends Controller
{
    public function summary(Request $request): JsonResponse
    {
        $to = $this->parseDate($request->get('to')) ?? Carbon::now();
        $from = $this->parseDate($request->get('from')) ?? $to->copy()->subMonthsNoOverflow(11)->startOfMonth();
        $from = $from->startOfDay();
        $to = $to->endOfDay();

        // Tổng doanh thu (thanh toán thành công)
        $totalRevenue = DB::table('payments')
            ->where('status', 'success')
            ->whereBetween('paid_at', [$from, $to])
            ->sum(DB::raw('COALESCE(amount,0) - COALESCE(discount_amount,0)'));

        // Doanh thu theo tháng
        $revenueMonthly = DB::table('payments')
            ->selectRaw("TO_CHAR(DATE_TRUNC('month', paid_at), 'YYYY-MM') as month")
            ->selectRaw('SUM(COALESCE(amount,0) - COALESCE(discount_amount,0)) as revenue')
            ->where('status', 'success')
            ->whereBetween('paid_at', [$from, $to])
            ->groupBy('month')
            ->orderBy('month')
            ->get();

        // Đơn đặt tour theo tháng
        $bookingsMonthly = DB::table('bookings')
            ->selectRaw("TO_CHAR(DATE_TRUNC('month', booking_date), 'YYYY-MM') as month")
            ->selectRaw('COUNT(*) as count')
            ->whereBetween('booking_date', [$from, $to])
            ->groupBy('month')
            ->orderBy('month')
            ->get();

        $bookingsTotal = DB::table('bookings')
            ->whereBetween('booking_date', [$from, $to])
            ->count();

        $newCustomers = DB::table('users')
            ->where('role', 'customer')
            ->whereBetween('created_at', [$from, $to])
            ->count();

        $partnersTotal = DB::table('partners')->count();
        $partnersActive = DB::table('partners')->where('status', 'approved')->count();
        $partnersNew = DB::table('partners')
            ->whereBetween('created_at', [$from, $to])
            ->count();

        // Top doanh thu theo đối tác
        $topPartners = DB::table('payments')
            ->join('bookings', 'payments.booking_id', '=', 'bookings.id')
            ->join('tour_schedules', 'bookings.tour_schedule_id', '=', 'tour_schedules.id')
            ->join('tours', 'tour_schedules.tour_id', '=', 'tours.id')
            ->join('partners', 'tours.partner_id', '=', 'partners.id')
            ->where('payments.status', 'success')
            ->whereBetween('payments.paid_at', [$from, $to])
            ->groupBy('partners.id', 'partners.company_name')
            ->select(
                'partners.id as partner_id',
                'partners.company_name',
                DB::raw('SUM(COALESCE(payments.amount,0) - COALESCE(payments.discount_amount,0)) as revenue'),
                DB::raw('COUNT(DISTINCT bookings.id) as bookings_count')
            )
            ->orderByDesc('revenue')
            ->limit(10)
            ->get();

        return response()->json([
            'period' => [
                'from' => $from->toDateString(),
                'to' => $to->toDateString(),
            ],
            'metrics' => [
                'revenue_total' => (float) $totalRevenue,
                'bookings_total' => $bookingsTotal,
                'new_customers' => $newCustomers,
                'partners_total' => $partnersTotal,
                'partners_active' => $partnersActive,
                'partners_new' => $partnersNew,
            ],
            'revenue_monthly' => $revenueMonthly,
            'bookings_monthly' => $bookingsMonthly,
            'top_partners' => $topPartners,
        ]);
    }

    private function parseDate($value): ?Carbon
    {
        if (!$value) {
            return null;
        }

        try {
            return Carbon::parse($value);
        } catch (\Throwable) {
            return null;
        }
    }
}
