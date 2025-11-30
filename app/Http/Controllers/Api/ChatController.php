<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Booking;
use App\Models\Promotion;
use App\Models\Tour;
use App\Services\AutoPromotionService;
use App\Services\ChatbotService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

class ChatController extends Controller
{
    public function __construct(
        private ChatbotService $chatbot,
        private AutoPromotionService $autoPromotions
    ) {
    }

    public function chat(Request $request): JsonResponse
    {
        $data = $request->validate([
            'message' => 'required|string|max:2000',
            'language' => 'nullable|string',
            'history' => 'nullable|array|max:10',
            'history.*.role' => 'required_with:history|string|in:user,assistant',
            'history.*.content' => 'required_with:history|string|max:2000',
        ]);

        $language = $this->normalizeLanguage($data['language'] ?? 'vi');
        $user = $request->user();
        $history = $this->sanitizeHistory($data['history'] ?? []);

        $tours = $this->getCandidateTours($user);
        $promotions = $this->getHighlightedPromotions();
        $recentBookings = $this->getRecentBookings($user);

        $context = $this->buildContext($tours, $promotions, $recentBookings, $user);

        $systemPrompt = $this->buildSystemPrompt($language);
        $userPrompt = sprintf(
            "User message:\n%s\n\nContext:\n%s",
            $data['message'],
            $context
        );

        $reply = $this->chatbot->ask($systemPrompt, $userPrompt, $history);

        return response()->json([
            'reply' => $reply,
            'language' => $language,
        ]);
    }

    private function normalizeLanguage(?string $lang): string
    {
        $lang = strtolower(trim((string) $lang));

        return match ($lang) {
            'en', 'eng', 'english', 'en-us', 'en_us' => 'en',
            'vi', 'vie', 'vietnamese', 'vi-vn', 'vi_vn' => 'vi',
            default => 'vi',
        };
    }

    private function getCandidateTours($user)
    {
        $query = Tour::approved()
            ->with([
                'partner:user_id,id,company_name',
                'categories:id,name',
                'schedules' => function ($q) {
                    $q->whereDate('start_date', '>=', Carbon::today()->toDateString())
                        ->orderBy('start_date')
                        ->limit(3);
                },
            ])
            ->orderByDesc('created_at')
            ->limit(8);

        $tours = $query->get();
        $this->autoPromotions->attachToTours($tours);

        return $tours;
    }

    private function getHighlightedPromotions()
    {
        return Promotion::active()
            ->where('auto_apply', false)
            ->orderBy('valid_from')
            ->limit(5)
            ->get();
    }

    private function getRecentBookings($user)
    {
        if (!$user) {
            return collect();
        }

        return Booking::with(['tourSchedule.tour'])
            ->where('user_id', $user->id)
            ->orderByDesc('booking_date')
            ->limit(5)
            ->get();
    }

    private function buildContext($tours, $promotions, $recentBookings, $user): string
    {
        $tourLines = $tours->map(function (Tour $tour) {
            $schedule = optional($tour->schedules->first());
            $priceAfter = $tour->price_after_discount ?? $tour->base_price;

            return sprintf(
                "- %s (%s, %s ngày)\n  Giá: %s VND%s\n  Khởi hành gần nhất: %s\n  Tags: %s\n  Mô tả: %s",
                $tour->title,
                $tour->destination,
                $tour->duration ?? 'N/A',
                number_format((float) $priceAfter, 0, '.', ','),
                $tour->auto_promotion
                    ? sprintf(" (đã giảm %s)", $tour->auto_promotion['description'] ?? 'khuyến mãi tự động')
                    : '',
                $schedule?->start_date?->format('d/m/Y') ?? 'Không có',
                implode(', ', $tour->categories->pluck('name')->all()),
                Str::of($tour->description)->limit(120)
            );
        })->implode("\n\n");

        $promotionLines = $promotions->map(function (Promotion $promotion) {
            return sprintf(
                "- Mã %s: giảm %s (%s - %s)",
                $promotion->code,
                $promotion->discount_type === 'fixed'
                    ? number_format($promotion->value, 0, '.', ',') . ' VND'
                    : $promotion->value . '%',
                optional($promotion->valid_from)->format('d/m/Y') ?? 'N/A',
                optional($promotion->valid_to)->format('d/m/Y') ?? 'N/A'
            );
        })->implode("\n");

        $bookingLines = $recentBookings->map(function (Booking $booking) {
            $tour = $booking->tourSchedule?->tour;
            $schedule = $booking->tourSchedule;
            $paymentStatus = $booking->payment_status ?? 'N/A';

            return sprintf(
                "- Booking #%s: tour \"%s\", khởi hành %s, trạng thái: %s, thanh toán: %s",
                $booking->id,
                $tour?->title ?? 'N/A',
                optional($schedule?->start_date)->format('d/m/Y') ?? 'N/A',
                $booking->status ?? 'N/A',
                $paymentStatus
            );
        })->implode("\n");

        $profile = $user
            ? sprintf("Người dùng: %s, email: %s.", $user->name, $user->email)
            : 'Người dùng chưa đăng nhập.';

        return trim(sprintf(
            "%s\n\nTour đề xuất:\n%s\n\nMã khuyến mãi đang hoạt động:\n%s\n\nĐơn gần đây (nếu có):\n%s",
            $profile,
            $tourLines ?: 'Không có tour nổi bật.',
            $promotionLines ?: 'Không có khuyến mãi công khai.',
            $bookingLines ?: 'Chưa có thông tin đơn gần.'
        ));
    }

    private function sanitizeHistory(array $history): array
    {
        return collect($history)
            ->take(10)
            ->map(fn ($item) => [
                'role' => $item['role'],
                'content' => $item['content'],
            ])
            ->all();
    }

    private function buildSystemPrompt(string $language): string
    {
        $langInstruction = $language === 'en'
            ? 'Respond in fluent English. If user switches to Vietnamese, follow their preference.'
            : 'Trả lời bằng tiếng Việt tự nhiên. Nếu người dùng muốn tiếng Anh, hãy đáp ứng.';

        return <<<PROMPT
You are TravelMate, an AI travel assistant for a Southeast Asia tour platform.
- Use the tour and booking context to answer questions, and highlight promotions.
- Mention price after discount when available and remind users discounts are dynamic.
- Offer to help filter by destination, budget, or travel date.
- Never fabricate data; if context lacking, say you will pass request to human agent.
- Default: keep answers within 5 sentences or 5 bullets; avoid long prose.
- If user asks to be short/brief/concise, answer within 2 sentences or 3 bullets max.
- When listing tours, show at most 3 items with price succinctly; avoid filler.
- If user asks about their bookings, use the booking list in context; otherwise say no info.
- $langInstruction
PROMPT;
    }
}
