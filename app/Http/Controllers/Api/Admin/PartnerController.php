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
use Illuminate\Validation\Rule;

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

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name' => 'required|string|max:255',
            'email' => 'required|email|unique:users,email',
            'phone' => 'nullable|string|max:20|unique:users,phone',
            'password' => 'required|string|min:6|confirmed',
            'company_name' => 'required|string|max:255',
            'tax_code' => 'nullable|string|max:50',
            'address' => 'nullable|string|max:255',
            'status' => ['required', Rule::in(['pending', 'approved', 'rejected'])],
        ]);

        $partner = DB::transaction(function () use ($data) {
            $user = User::create([
                'name' => $data['name'],
                'email' => $data['email'],
                'phone' => $data['phone'] ?? null,
                'password' => Hash::make($data['password']),
                'role' => 'partner',
                'status' => $data['status'] === 'rejected' ? 'inactive' : 'active',
            ]);

            return Partner::create([
                'user_id' => $user->id,
                'company_name' => $data['company_name'],
                'tax_code' => $data['tax_code'] ?? null,
                'address' => $data['address'] ?? null,
                'status' => $data['status'],
            ]);
        });

        return response()->json([
            'message' => 'Tạo đối tác thành công.',
            'partner' => $partner->load('user'),
        ], 201);
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
            'tax_code' => 'sometimes|nullable|string|max:50',
            'address' => 'sometimes|nullable|string|max:255',
            'status' => ['sometimes', Rule::in(['pending', 'approved', 'rejected'])],
        ]);

        $partner->fill($data);

        if (isset($data['status'])) {
            $partner->status = $data['status'];
            if ($data['status'] === 'rejected') {
                $partner->user->status = 'inactive';
            } elseif ($data['status'] === 'approved') {
                $partner->user->status = 'active';
            }
            $partner->user->save();
        }

        $partner->save();

        return response()->json([
            'message' => 'Cập nhật thông tin đối tác thành công.',
            'partner' => $partner->fresh('user'),
        ]);
    }
}
