<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\Tour;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class TourController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $tours = Tour::with(['partner.user'])
            ->when($request->filled('status'), fn ($q) => $q->where('status', $request->status))
            ->when($request->filled('partner_id'), fn ($q) => $q->where('partner_id', $request->partner_id))
            ->when($request->filled('search'), function ($query) use ($request) {
                $term = '%' . $request->search . '%';
                $query->where(function ($q) use ($term) {
                    $q->where('title', 'like', $term)
                        ->orWhere('destination', 'like', $term);
                });
            })
            ->orderByDesc('created_at')
            ->paginate($request->integer('per_page', 20));

        return response()->json($tours);
    }

    public function show(string $id): JsonResponse
    {
        $tour = Tour::with(['partner.user', 'schedules'])->findOrFail($id);

        return response()->json($tour);
    }

    public function updateStatus(Request $request, string $id): JsonResponse
    {
        $data = $request->validate([
            'status' => ['required', Rule::in(['pending', 'approved', 'rejected'])],
        ]);

        $tour = Tour::findOrFail($id);

        DB::transaction(function () use ($tour, $data) {
            $tour->status = $data['status'];
            $tour->save();
        });

        return response()->json([
            'message' => 'Cập nhật trạng thái tour thành công.',
            'tour' => $tour->fresh(),
        ]);
    }
}
