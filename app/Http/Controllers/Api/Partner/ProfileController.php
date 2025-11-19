<?php

namespace App\Http\Controllers\Api\Partner;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ProfileController extends Controller
{
    public function show(Request $request): JsonResponse
    {
        $partner = $this->getAuthenticatedPartner();
        if (!$partner) {
            return response()->json(['message' => 'Partner account is not approved.'], 403);
        }

        return response()->json([
            'profile' => $partner,
        ]);
    }

    public function update(Request $request): JsonResponse
    {
        $partner = $this->getAuthenticatedPartner();
        if (!$partner) {
            return response()->json(['message' => 'Partner account is not approved.'], 403);
        }

        $data = $request->validate([
            'company_name' => 'sometimes|required|string|max:255',
            'tax_code' => 'sometimes|nullable|string|max:100',
            'address' => 'sometimes|nullable|string|max:255',
            'contact_name' => 'sometimes|nullable|string|max:255',
            'contact_email' => 'sometimes|nullable|email|max:255',
            'contact_phone' => 'sometimes|nullable|string|max:50',
            'business_type' => 'sometimes|nullable|string|max:255',
            'description' => 'sometimes|nullable|string',
            'invoice_company_name' => 'sometimes|nullable|string|max:255',
            'invoice_tax_code' => 'sometimes|nullable|string|max:100',
            'invoice_address' => 'sometimes|nullable|string|max:255',
            'invoice_email' => 'sometimes|nullable|email|max:255',
            'invoice_vat_rate' => 'sometimes|nullable|numeric|min:0|max:50',
        ]);

        if (empty($data)) {
            throw ValidationException::withMessages([
                'profile' => ['No profile data provided.'],
            ]);
        }

        DB::table('partners')
            ->where('id', $partner->id)
            ->update(array_merge($data, [
                'updated_at' => now(),
            ]));

        $partner = $this->getAuthenticatedPartner();

        return response()->json([
            'message' => 'Partner profile updated successfully.',
            'profile' => $partner,
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
