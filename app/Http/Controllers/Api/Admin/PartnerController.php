<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\Booking;
use App\Models\Partner;
use App\Models\Tour;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class PartnerController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $partners = Partner::with('user')
            ->when($request->filled('status'), fn ($q) => $q->where('status', $request->status))
            ->orderByDesc('created_at')
            ->paginate($request->integer('per_page', 20));

        return response()->json($partners);
    }

    public function show(string $id): JsonResponse
    {
        $partner = Partner::with('user')->findOrFail($id);

        $toursCount = Tour::where('partner_id', $partner->id)->count();
        $bookingsCount = DB::table('bookings')
            ->join('tour_schedules', 'bookings.tour_schedule_id', '=', 'tour_schedules.id')
            ->join('tours', 'tour_schedules.tour_id', '=', 'tours.id')
            ->where('tours.partner_id', $partner->id)
            ->count();

        return response()->json([
            'partner' => $partner,
            'stats' => [
                'tours_count' => $toursCount,
                'bookings_count' => $bookingsCount,
            ],
        ]);
    }

    public function update(Request $request, string $id): JsonResponse
    {
        $partner = Partner::with('user')->findOrFail($id);

        $data = $request->validate([
            'company_name' => 'sometimes|required|string|max:255',
            'business_type' => 'sometimes|nullable|string|max:255',
            'tax_code' => 'sometimes|nullable|string|max:50',
            'address' => 'sometimes|nullable|string|max:255',
            'description' => 'sometimes|nullable|string',
            'contact_name' => 'sometimes|required|string|max:255',
            'contact_email' => [
                'sometimes',
                'required',
                'email',
                Rule::unique('partners', 'contact_email')->ignore($partner->id)->whereNotNull('contact_email'),
            ],
            'contact_phone' => [
                'sometimes',
                'required',
                'string',
                'max:30',
                Rule::unique('partners', 'contact_phone')->ignore($partner->id)->whereNotNull('contact_phone'),
            ],
            'status' => ['sometimes', Rule::in(['pending', 'approved', 'rejected'])],
        ]);

        $previousStatus = $partner->status;
        $generatedPassword = null;

        DB::transaction(function () use ($data, $partner, &$generatedPassword, $previousStatus) {
            if (!empty($data)) {
                $partner->fill(array_intersect_key($data, array_flip([
                    'company_name',
                    'business_type',
                    'tax_code',
                    'address',
                    'description',
                    'contact_name',
                    'contact_email',
                    'contact_phone',
                    'status',
                ])));
            }

            if (($data['status'] ?? null) === 'approved' && $previousStatus !== 'approved') {
                if (!$partner->contact_email) {
                    throw ValidationException::withMessages([
                        'contact_email' => ['Đối tác chưa có email liên hệ.'],
                    ]);
                }

                if (!$partner->user_id) {
                    $generatedPassword = Str::random(12);
                    $user = User::create([
                        'name' => $partner->contact_name ?: $partner->company_name,
                        'email' => $partner->contact_email,
                        'phone' => $partner->contact_phone,
                        'password' => Hash::make($generatedPassword),
                        'role' => 'partner',
                        'status' => 'active',
                    ]);

                    $partner->user_id = $user->id;
                } else {
                    $partner->user?->update(['status' => 'active']);
                }

                $partner->approved_at = now();
                $partner->status = 'approved';
            }

            if (($data['status'] ?? null) === 'rejected' && $partner->user) {
                $partner->user->status = 'inactive';
                $partner->user->save();
            }

            if (($data['status'] ?? null) === 'pending' && $partner->user) {
                $partner->user->status = 'inactive';
                $partner->user->save();
            }

            $partner->save();
        });

        if ($partner->status === 'approved' && $previousStatus !== 'approved') {
            $this->sendPartnerApprovalEmail($partner->fresh('user'), $generatedPassword);
        }

        return response()->json([
            'message' => 'Cập nhật thông tin đối tác thành công.',
            'partner' => $partner->fresh('user'),
        ]);
    }

    private function sendPartnerApprovalEmail(Partner $partner, ?string $plainPassword): void
    {
        if (!$partner->contact_email || !$plainPassword) {
            return;
        }

        $brevo = config('services.brevo');
        $apiKey = $brevo['api_key'] ?? null;
        $senderEmail = $brevo['sender_email'] ?? null;
        $senderName = $brevo['sender_name'] ?? config('app.name');

        if (!$apiKey || !$senderEmail) {
            logger()->warning('[PartnerApprovalEmail] Missing Brevo credentials.');
            return;
        }

        $html = view('emails.partners.approved', [
            'partner' => $partner,
            'password' => $plainPassword,
        ])->render();

        $response = Http::withHeaders([
            'api-key' => $apiKey,
            'accept' => 'application/json',
            'content-type' => 'application/json',
        ])->post('https://api.brevo.com/v3/smtp/email', [
            'sender' => [
                'email' => $senderEmail,
                'name' => $senderName,
            ],
            'to' => [
                [
                    'email' => $partner->contact_email,
                    'name' => $partner->contact_name ?: $partner->company_name,
                ],
            ],
            'subject' => 'Yêu cầu hợp tác đã được duyệt',
            'htmlContent' => $html,
        ]);

        if ($response->failed()) {
            logger()->warning('[PartnerApprovalEmail] Failed', [
                'partner_id' => $partner->id,
                'status' => $response->status(),
                'body' => $response->body(),
            ]);
        }
    }
}

