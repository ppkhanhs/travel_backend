<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Promotion;
use App\Models\Tour;
use App\Services\AutoPromotionService;
use App\Services\ChatbotService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
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
            'language' => 'nullable|in:vi,en',
        ]);

        $language = $data['language'] ?? 'vi';
        $user = $request->user();

        $tours = $this->getCandidateTours($user);
        $promotions = $this->getHighlightedPromotions();

        $context = $this->buildContext($tours, $promotions, $user);

        $systemPrompt = $this->buildSystemPrompt($language);
        $userPrompt = sprintf(
            "User message:\n%s\n\nContext:\n%s",
            $data['message'],
            $context
        );

        $reply = $this->chatbot->ask($systemPrompt, $userPrompt);

        return response()->json([
            'reply' => $reply,
            'language' => $language,
        ]);
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

    private function buildContext($tours, $promotions, $user): string
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

        $profile = $user
            ? sprintf("Người dùng: %s, email: %s.", $user->name, $user->email)
            : 'Người dùng chưa đăng nhập.';

        return trim(sprintf(
            "%s\n\nTour đề xuất:\n%s\n\nMã khuyến mãi đang hoạt động:\n%s",
            $profile,
            $tourLines ?: 'Không có tour nổi bật.',
            $promotionLines ?: 'Không có khuyến mãi công khai.'
        ));
    }

    private function buildSystemPrompt(string $language): string
    {
        $langInstruction = $language === 'en'
            ? 'Respond in fluent English. If user switches to Vietnamese, follow their preference.'
            : 'Trả lời bằng tiếng Việt tự nhiên. Nếu người dùng muốn tiếng Anh, hãy đáp ứng.';

        return <<<PROMPT
You are TravelMate, an AI travel assistant for a Southeast Asia tour platform.
- Use the tour context to recommend itineraries, highlight promotions, and answer questions.
- Mention price after discount when available and remind users discounts are dynamic.
- Offer to help filter by destination, budget, or travel date.
- Never fabricate data; if context lacking, say you will pass request to human agent.
- Keep answers concise (<= 4 paragraphs) and add bullet lists when listing tours.
- $langInstruction
PROMPT;
    }
}
