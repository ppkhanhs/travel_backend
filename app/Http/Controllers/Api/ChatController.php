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

        $context = $this->buildContext($tours, $promotions, $user);

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

    private function buildContext($tours, $promotions, $user): string
    {
        $tourLines = $tours->map(function (Tour $tour) {
            $schedule = optional($tour->schedules->first());
            $priceAfter = $tour->price_after_discount ?? $tour->base_price;

            return sprintf(
                "- %s (%s, %s ngÃ y)\n  GiÃ¡: %s VND%s\n  Khá»Ÿi hÃ nh gáº§n nháº¥t: %s\n  Tags: %s\n  MÃ´ táº£: %s",
                $tour->title,
                $tour->destination,
                $tour->duration ?? 'N/A',
                number_format((float) $priceAfter, 0, '.', ','),
                $tour->auto_promotion
                    ? sprintf(" (Ä‘Ã£ giáº£m %s)", $tour->auto_promotion['description'] ?? 'khuyáº¿n mÃ£i tá»± Ä‘á»™ng')
                    : '',
                $schedule?->start_date?->format('d/m/Y') ?? 'KhÃ´ng cÃ³',
                implode(', ', $tour->categories->pluck('name')->all()),
                Str::of($tour->description)->limit(120)
            );
        })->implode("\n\n");

        $promotionLines = $promotions->map(function (Promotion $promotion) {
            return sprintf(
                "- MÃ£ %s: giáº£m %s (%s - %s)",
                $promotion->code,
                $promotion->discount_type === 'fixed'
                    ? number_format($promotion->value, 0, '.', ',') . ' VND'
                    : $promotion->value . '%',
                optional($promotion->valid_from)->format('d/m/Y') ?? 'N/A',
                optional($promotion->valid_to)->format('d/m/Y') ?? 'N/A'
            );
        })->implode("\n");

        $profile = $user
            ? sprintf("NgÆ°á»i dÃ¹ng: %s, email: %s.", $user->name, $user->email)
            : 'NgÆ°á»i dÃ¹ng chÆ°a Ä‘Äƒng nháº­p.';

        return trim(sprintf(
            "%s\n\nTour Ä‘á» xuáº¥t:\n%s\n\nMÃ£ khuyáº¿n mÃ£i Ä‘ang hoáº¡t Ä‘á»™ng:\n%s",
            $profile,
            $tourLines ?: 'KhÃ´ng cÃ³ tour ná»•i báº­t.',
            $promotionLines ?: 'KhÃ´ng cÃ³ khuyáº¿n mÃ£i cÃ´ng khai.'
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
- Use the tour context to recommend itineraries, highlight promotions, and answer questions.
- Mention price after discount when available and remind users discounts are dynamic.
- Offer to help filter by destination, budget, or travel date.
- Never fabricate data; if context lacking, say you will pass request to human agent.
- Default: keep answers within 5 sentences or 5 bullets; avoid long prose.
- If user asks to be short/brief/concise, answer within 2 sentences or 3 bullets max.
- When listing tours, show at most 3 items with price succinctly; avoid filler.
- $langInstruction
PROMPT;
    }
}