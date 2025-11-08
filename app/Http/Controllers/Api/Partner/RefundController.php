<?php

namespace App\Http\Controllers\Api\Partner;

use App\Http\Controllers\Controller;
use App\Models\RefundRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class RefundController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $partner = $this->getAuthenticatedPartner();
        if (!$partner) {
            return response()->json(['message' => 'Partner account is not approved or unavailable.'], 403);
        }

        $refunds = RefundRequest::query()
            ->with(['booking.user', 'booking.tourSchedule.tour'])
            ->where('partner_id', $partner->id)
            ->when($request->filled('status'), fn ($q) => $q->where('status', $request->status))
            ->orderByDesc('created_at')
            ->paginate($request->integer('per_page', 15));

        return response()->json($refunds);
    }

    public function update(Request $request, string $id): JsonResponse
    {
        $partner = $this->getAuthenticatedPartner();
        if (!$partner) {
            return response()->json(['message' => 'Partner account is not approved or unavailable.'], 403);
        }

        $refund = RefundRequest::query()
            ->where('id', $id)
            ->where('partner_id', $partner->id)
            ->firstOrFail();

        $data = $request->validate([
            'status' => ['required', Rule::in(['await_customer_confirm', 'rejected'])],
            'partner_message' => 'nullable|string|max:1000',
            'proof' => 'nullable|file|max:5120|mimes:jpg,jpeg,png,pdf',
        ]);

        if ($refund->status !== 'pending_partner' && $refund->status !== 'await_customer_confirm') {
            throw ValidationException::withMessages([
                'refund' => ['This refund request cannot be updated at the current status.'],
            ]);
        }

        if ($data['status'] === 'await_customer_confirm' && !$request->hasFile('proof')) {
            throw ValidationException::withMessages([
                'proof' => ['Proof of transfer is required.'],
            ]);
        }

        DB::transaction(function () use ($refund, $data, $request) {
            if ($request->hasFile('proof')) {
                $path = $request->file('proof')->store('refund-proofs', 'public');
                $refund->proof_url = Storage::disk('public')->url($path);
            }

            $refund->status = $data['status'];
            $refund->partner_message = $data['partner_message'] ?? null;
            $refund->partner_marked_at = now();
            $refund->save();
        });

        return response()->json([
            'message' => 'Refund request updated successfully.',
            'refund_request' => $refund->fresh(),
        ]);
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
}

