<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\Promotion;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class PromotionController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $promotions = Promotion::orderByDesc('created_at')
            ->paginate($request->integer('per_page', 20));

        return response()->json($promotions);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'code' => 'required|string|max:50|unique:promotions,code',
            'discount_type' => ['required', Rule::in(['percent', 'percentage', 'fixed'])],
            'value' => 'required|numeric|min:0',
            'max_usage' => 'nullable|integer|min:1',
            'valid_from' => 'nullable|date',
            'valid_to' => 'nullable|date|after_or_equal:valid_from',
            'is_active' => 'boolean',
        ]);

        $promotion = Promotion::create($data);

        return response()->json([
            'message' => 'Tạo mã khuyến mãi thành công.',
            'promotion' => $promotion,
        ], 201);
    }

    public function update(Request $request, string $id): JsonResponse
    {
        $promotion = Promotion::findOrFail($id);

        $data = $request->validate([
            'code' => [
                'sometimes',
                'required',
                'string',
                'max:50',
                Rule::unique('promotions', 'code')->ignore($promotion->id),
            ],
            'discount_type' => ['sometimes', Rule::in(['percent', 'percentage', 'fixed'])],
            'value' => 'sometimes|numeric|min:0',
            'max_usage' => 'sometimes|nullable|integer|min:1',
            'valid_from' => 'sometimes|nullable|date',
            'valid_to' => 'sometimes|nullable|date|after_or_equal:valid_from',
            'is_active' => 'sometimes|boolean',
        ]);

        $promotion->update($data);

        return response()->json([
            'message' => 'Cập nhật mã khuyến mãi thành công.',
            'promotion' => $promotion,
        ]);
    }

    public function destroy(string $id): JsonResponse
    {
        $promotion = Promotion::findOrFail($id);
        $promotion->delete();

        return response()->json([
            'message' => 'Xóa mã khuyến mãi thành công.',
        ]);
    }
}
