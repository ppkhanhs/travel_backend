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
            ->where('partner_id', $partner->id)
            ->where('auto_apply', true)
            ->when($request->filled('tour_id'), fn ($q) => $q->where('tour_id', $request->tour_id))
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
            'tour_id' => 'required|uuid',
            'discount_type' => ['required', Rule::in(['percent', 'percentage', 'fixed'])],
            'value' => 'required|numeric|min:0',
            'max_usage' => 'nullable|integer|min:1',
            'valid_from' => 'nullable|date',
            'valid_to' => 'nullable|date|after_or_equal:valid_from',
            'description' => 'nullable|string',
            'is_active' => 'boolean',
        ]);

        $tour = DB::table('tours')
            ->where('id', $data['tour_id'])
            ->where('partner_id', $partner->id)
            ->first();

        if (!$tour) {
            return response()->json(['message' => 'Tour not found or unavailable.'], 404);
        }

        $promotion = Promotion::create(array_merge($data, [
            'code' => $this->generateCode($partner->id),
            'auto_apply' => true,
            'partner_id' => $partner->id,
            'is_active' => $data['is_active'] ?? true,
        ]));

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
            ->where('id', $id)
            ->where('partner_id', $partner->id)
            ->where('auto_apply', true)
            ->firstOrFail();

        $data = $request->validate([
            'discount_type' => ['sometimes', Rule::in(['percent', 'percentage', 'fixed'])],
            'value' => 'sometimes|numeric|min:0',
            'max_usage' => 'sometimes|nullable|integer|min:1',
            'valid_from' => 'sometimes|nullable|date',
            'valid_to' => 'sometimes|nullable|date|after_or_equal:valid_from',
            'description' => 'sometimes|nullable|string',
            'is_active' => 'sometimes|boolean',
        ]);

        $promotion->update($data);

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
            ->where('auto_apply', true)
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

    private function generateCode(string $partnerId): string
    {
        return sprintf('AUTO-%s', Str::upper(Str::random(8)));
    }
}

