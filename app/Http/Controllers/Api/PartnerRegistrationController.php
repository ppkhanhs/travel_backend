<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Partner;
use App\Models\User;
use App\Notifications\AdminAlertNotification;
use App\Services\NotificationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class PartnerRegistrationController extends Controller
{
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'company_name' => 'required|string|max:255',
            'business_type' => 'required|string|max:255',
            'contact_name' => 'required|string|max:255',
            'contact_email' => [
                'required',
                'email',
                Rule::unique('partners', 'contact_email')->whereNotNull('contact_email'),
                Rule::unique('users', 'email'),
            ],
            'contact_phone' => [
                'required',
                'string',
                'max:30',
                Rule::unique('partners', 'contact_phone')->whereNotNull('contact_phone'),
                Rule::unique('users', 'phone'),
            ],
            'address' => 'nullable|string|max:255',
            'tax_code' => 'nullable|string|max:50',
            'description' => 'nullable|string',
        ]);

        $partner = Partner::create([
            'company_name' => $data['company_name'],
            'business_type' => $data['business_type'],
            'contact_name' => $data['contact_name'],
            'contact_email' => $data['contact_email'],
            'contact_phone' => $data['contact_phone'],
            'address' => $data['address'] ?? null,
            'tax_code' => $data['tax_code'] ?? null,
            'description' => $data['description'] ?? null,
            'status' => 'pending',
        ]);

        app(NotificationService::class)->notifyAdmins(
            new AdminAlertNotification(
                'partner_registration',
                'Đối tác mới đăng ký',
                sprintf('Đối tác %s vừa đăng ký, cần duyệt hồ sơ.', $partner->company_name ?? 'N/A'),
                ['partner_id' => $partner->id]
            )
        );

        return response()->json([
            'message' => 'Chúng tôi đã nhận được thông tin. Đội ngũ xét duyệt sẽ liên hệ trong thời gian sớm nhất.',
            'partner' => $partner,
        ], 201);
    }
}
