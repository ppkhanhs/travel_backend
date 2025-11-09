<?php

namespace App\Http\Controllers\Api\Partner;

use App\Http\Controllers\Controller;
use App\Models\Promotion;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class PromotionController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $partner = $this->getAuthenticatedPartner();
        if (!$partner) {
            return response()->json(['message' => 'Partner account is not approved or unavailable.'], 403);
        }

        $promotions = Promotion::query()
            ->with('tours:id,title,destination')
            ->where('partner_id', $partner->id)
            ->when($request->filled('type'), fn ($q) => $q->where('type', $request->type))
            ->when($request->filled('tour_id'), function ($query) use ($request) {
                $tourId = $request->tour_id;
                $query->where(function ($q) use ($tourId) {
                    $q->whereHas('tours', fn ($sub) => $sub->where('tours.id', $tourId))
                        ->orWhere('tour_id', $tourId);
                });
            })
            ->orderByDesc('valid_from')
            ->paginate($request->integer('per_page', 20));

        return response()->json($promotions);
    }

    public function store(Request $request): JsonResponse
    {
        $partner = $this->getAuthenticatedPartner();
        if (!$partner) {
            return response()->json(['message' => 'Partner account is not approved or unavailable.'], 403);
        }

        $data = $request->validate([
            'type' => ['required', Rule::in(['auto', 'voucher'])],
            'tour_ids' => 'required|array|min:1',
            'tour_ids.*' => 'uuid|distinct',
            'discount_type' => ['required', Rule::in(['percent', 'percentage', 'fixed'])],
            'value' => 'required|numeric|min:0',
            'max_usage' => 'nullable|integer|min:1',
            'valid_from' => 'nullable|date',
            'valid_to' => 'nullable|date|after_or_equal:valid_from',
            'description' => 'nullable|string',
            'is_active' => 'boolean',
            'auto_issue_on_cancel' => 'nullable|boolean',
            'code' => [
                'nullable',
                'string',
                'max:50',
                Rule::unique('promotions', 'code')->where(fn ($q) => $q->whereNotNull('code')),
            ],
        ]);

        $tourCount = DB::table('tours')
            ->whereIn('id', $data['tour_ids'])
            ->where('partner_id', $partner->id)
            ->count();

        if ($tourCount !== count($data['tour_ids'])) {
            return response()->json(['message' => 'One or more tours are unavailable.'], 404);
        }

        $isAuto = $data['type'] === 'auto';
        $code = $this->resolvePromotionCode($data['code'] ?? null, $partner->id, $isAuto);

        $promotion = Promotion::create([
            'code' => $code,
            'type' => $data['type'],
            'description' => $data['description'] ?? null,
            'partner_id' => $partner->id,
            'discount_type' => $data['discount_type'],
            'value' => $data['value'],
            'max_usage' => $data['max_usage'] ?? null,
            'valid_from' => $data['valid_from'] ?? null,
            'valid_to' => $data['valid_to'] ?? null,
            'is_active' => $data['is_active'] ?? true,
            'auto_apply' => $isAuto,
            'auto_issue_on_cancel' => !$isAuto && ($data['auto_issue_on_cancel'] ?? false),
        ]);

        $promotion->tours()->sync($data['tour_ids']);
        $promotion->load('tours:id,title,destination');

        return response()->json([
            'message' => 'Promotion created successfully.',
            'promotion' => $promotion,
        ], 201);
    }

    public function update(Request $request, string $id): JsonResponse
    {
        $partner = $this->getAuthenticatedPartner();
        if (!$partner) {
            return response()->json(['message' => 'Partner account is not approved or unavailable.'], 403);
        }

        $promotion = Promotion::query()
            ->with('tours')
            ->where('id', $id)
            ->where('partner_id', $partner->id)
            ->firstOrFail();

        $data = $request->validate([
            'type' => ['sometimes', Rule::in(['auto', 'voucher'])],
            'tour_ids' => 'sometimes|array|min:1',
            'tour_ids.*' => 'uuid|distinct',
            'discount_type' => ['sometimes', Rule::in(['percent', 'percentage', 'fixed'])],
            'value' => 'sometimes|numeric|min:0',
            'max_usage' => 'sometimes|nullable|integer|min:1',
            'valid_from' => 'sometimes|nullable|date',
            'valid_to' => 'sometimes|nullable|date|after_or_equal:valid_from',
            'description' => 'sometimes|nullable|string',
            'is_active' => 'sometimes|boolean',
            'auto_issue_on_cancel' => 'sometimes|boolean',
            'code' => [
                'sometimes',
                'nullable',
                'string',
                'max:50',
                Rule::unique('promotions', 'code')
                    ->where(fn ($q) => $q->whereNotNull('code'))
                    ->ignore($promotion->id),
            ],
        ]);

        if (isset($data['tour_ids'])) {
            $tourCount = DB::table('tours')
                ->whereIn('id', $data['tour_ids'])
                ->where('partner_id', $partner->id)
                ->count();

            if ($tourCount !== count($data['tour_ids'])) {
                return response()->json(['message' => 'One or more tours are unavailable.'], 404);
            }
        }

        $payload = $data;

        if (isset($data['type'])) {
            $payload['auto_apply'] = $data['type'] === 'auto';
        }

        if (array_key_exists('auto_issue_on_cancel', $data)) {
            $payload['auto_issue_on_cancel'] = (bool) $data['auto_issue_on_cancel'];
        }

        $promotion->update($payload);

        if (isset($data['tour_ids'])) {
            $promotion->tours()->sync($data['tour_ids']);
        }

        $promotion->refresh()->load('tours:id,title,destination');

        return response()->json([
            'message' => 'Promotion updated successfully.',
            'promotion' => $promotion,
        ]);
    }

    public function destroy(string $id): JsonResponse
    {
        $partner = $this->getAuthenticatedPartner();
        if (!$partner) {
            return response()->json(['message' => 'Partner account is not approved or unavailable.'], 403);
        }

        $promotion = Promotion::query()
            ->where('id', $id)
            ->where('partner_id', $partner->id)
            ->firstOrFail();

        $promotion->delete();

        return response()->json(['message' => 'Promotion deleted successfully.']);
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

    private function resolvePromotionCode(?string $rawCode, string $partnerId, bool $isAuto): string
    {
        $code = is_string($rawCode) ? trim($rawCode) : '';

        if ($code !== '') {
            return $code;
        }

        return $this->generateCode($partnerId, $isAuto);
    }

    private function generateCode(string $partnerId, bool $isAuto = false): string
    {
        $prefix = $isAuto ? 'AUTO' : 'VCH';

        do {
            $code = sprintf('%s-%s', $prefix, Str::upper(Str::random(8)));
        } while (Promotion::query()->where('code', $code)->exists());

        return $code;
    }
}
