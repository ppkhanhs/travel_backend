<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\Rule;

class AdminAccountController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $admins = User::where('role', 'admin')
            ->when($request->filled('status'), fn ($q) => $q->where('status', $request->status))
            ->orderByDesc('created_at')
            ->paginate($request->integer('per_page', 20));

        return response()->json($admins);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name' => 'required|string|max:255',
            'email' => 'required|email|unique:users,email',
            'phone' => 'nullable|string|max:20|unique:users,phone',
            'password' => 'required|string|min:6|confirmed',
        ]);

        $attributes = [
            'name' => $data['name'],
            'email' => $data['email'],
            'phone' => $data['phone'] ?? null,
            'password' => Hash::make($data['password']),
            'role' => 'admin',
        ];

        if (Schema::hasColumn('users', 'status')) {
            $attributes['status'] = 'active';
        }

        $admin = User::create($attributes);

        return response()->json([
            'message' => 'Tạo tài khoản quản trị thành công.',
            'admin' => $admin,
        ], 201);
    }

    public function update(Request $request, string $id): JsonResponse
    {
        $admin = User::where('role', 'admin')->findOrFail($id);

        $data = $request->validate([
            'name' => 'sometimes|required|string|max:255',
            'email' => [
                'sometimes',
                'required',
                'email',
                Rule::unique('users', 'email')->ignore($admin->id),
            ],
            'phone' => [
                'sometimes',
                'nullable',
                'string',
                'max:20',
                Rule::unique('users', 'phone')->ignore($admin->id),
            ],
            'password' => 'sometimes|nullable|string|min:6|confirmed',
            'status' => ['sometimes', Rule::in(['active', 'inactive', 'suspended'])],
        ]);

        if (!empty($data['password'])) {
            $data['password'] = Hash::make($data['password']);
        } else {
            unset($data['password']);
        }

        if (!Schema::hasColumn('users', 'status')) {
            unset($data['status']);
        }

        $admin->update($data);

        return response()->json([
            'message' => 'Cập nhật tài khoản quản trị thành công.',
            'admin' => $admin,
        ]);
    }

    public function destroy(Request $request, string $id): JsonResponse
    {
        $admin = User::where('role', 'admin')->findOrFail($id);

        if ($request->user()->id === $admin->id) {
            return response()->json([
                'message' => 'Bạn không thể tự xóa tài khoản của chính mình.',
            ], 422);
        }

        $remainingAdmins = User::where('role', 'admin')->where('id', '!=', $admin->id)->count();
        if ($remainingAdmins === 0) {
            return response()->json([
                'message' => 'Hệ thống cần ít nhất một quản trị viên.',
            ], 422);
        }

        $admin->delete();

        return response()->json([
            'message' => 'Xóa tài khoản quản trị thành công.',
        ]);
    }
}
