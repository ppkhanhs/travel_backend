<?php

namespace App\Console\Commands;

use App\Models\Promotion;
use App\Models\Tour;
use App\Notifications\AdminAlertNotification;
use App\Services\NotificationService;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class SendAdminAlerts extends Command
{
    protected $signature = 'admin:alerts';

    protected $description = 'Send admin alerts: expiring schedules, expiring promotions, promotions reaching usage limit';

    public function handle(NotificationService $notifications): int
    {
        $now = Carbon::today();
        $soon = Carbon::today()->addDays(7);
        $alerts = 0;

        // Tours sắp hết lịch trình (lịch cuối cùng trong <=7 ngày, không có lịch mới sau đó)
        $tours = DB::table('tour_schedules')
            ->select('tour_id', DB::raw('MAX(end_date) as last_end'))
            ->groupBy('tour_id')
            ->havingRaw('MAX(end_date) <= ?', [$soon->toDateString()])
            ->get();

        if ($tours->isNotEmpty()) {
            $tourIds = $tours->pluck('tour_id')->all();
            $tourList = Tour::query()
                ->whereIn('id', $tourIds)
                ->where('status', 'approved')
                ->pluck('title', 'id');

            foreach ($tours as $tourRow) {
                $title = $tourList->get($tourRow->tour_id);
                if (!$title) {
                    continue;
                }

                $notifications->notifyAdmins(new AdminAlertNotification(
                    'tour_schedule_expiring',
                    'Lịch trình sắp hết hiệu lực',
                    sprintf('Tour "%s" sắp hết lịch trình (kết thúc %s). Đề nghị nhắc đối tác bổ sung lịch mới.', $title, $tourRow->last_end),
                    [
                        'tour_id' => (string) $tourRow->tour_id,
                        'last_end_date' => $tourRow->last_end,
                    ]
                ));
                $alerts++;
            }
        }

        // Khuyến mãi sắp hết hạn (3 ngày)
        $expiringPromos = Promotion::query()
            ->where('is_active', true)
            ->whereNotNull('valid_to')
            ->whereBetween('valid_to', [$now->toDateString(), $now->copy()->addDays(3)->toDateString()])
            ->get();

        foreach ($expiringPromos as $promo) {
            $notifications->notifyAdmins(new AdminAlertNotification(
                'promotion_expiring',
                'Khuyến mãi sắp hết hạn',
                sprintf('Khuyến mãi %s sẽ hết hạn vào %s.', $promo->code, optional($promo->valid_to)->toDateString()),
                [
                    'promotion_id' => $promo->id,
                    'code' => $promo->code,
                    'valid_to' => optional($promo->valid_to)->toDateString(),
                ]
            ));
            $alerts++;
        }

        // Khuyến mãi hết lượt sử dụng
        $usageCounts = DB::table('booking_promotions')
            ->join('bookings', 'booking_promotions.booking_id', '=', 'bookings.id')
            ->select('booking_promotions.promotion_id', DB::raw('COUNT(*) as usage'))
            ->whereNotIn('bookings.status', ['cancelled'])
            ->groupBy('booking_promotions.promotion_id')
            ->pluck('usage', 'promotion_id');

        $limitedPromos = Promotion::query()
            ->whereNotNull('max_usage')
            ->where('is_active', true)
            ->get();

        foreach ($limitedPromos as $promo) {
            $used = (int) ($usageCounts[$promo->id] ?? 0);
            if ($used >= (int) $promo->max_usage) {
                $notifications->notifyAdmins(new AdminAlertNotification(
                    'promotion_usage_full',
                    'Khuyến mãi đã hết lượt sử dụng',
                    sprintf('Khuyến mãi %s đã đạt %d/%d lượt.', $promo->code, $used, $promo->max_usage),
                    [
                        'promotion_id' => $promo->id,
                        'code' => $promo->code,
                        'used' => $used,
                        'max_usage' => (int) $promo->max_usage,
                    ]
                ));
                $alerts++;
            }
        }

        $this->info("Admin alerts sent: {$alerts}");

        return Command::SUCCESS;
    }
}
