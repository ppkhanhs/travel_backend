<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Validation\ValidationException;

class AuthController extends Controller
{
    public function register(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name' => 'required|string|max:255',
            'email' => 'required|email|unique:users,email',
            'phone' => 'nullable|string|unique:users,phone',
            'password' => 'required|string|min:6',
            'preferences' => 'sometimes|array|max:10',
            'preferences.*' => 'string|max:50',
        ]);

        $user = User::create([
            'name' => $data['name'],
            'email' => $data['email'],
            'phone' => $data['phone'] ?? null,
            'password' => bcrypt($data['password']),
            'role' => 'customer',
            'preferences' => $this->sanitizePreferences($data['preferences'] ?? []),
        ]);

        return response()->json([
            'message' => 'Đăng ký thành công',
            'user' => $user,
        ], 201);
    }

    public function login(Request $request): JsonResponse
    {
        $request->validate([
            'email' => 'required',
            'password' => 'required',
        ]);

        $user = User::where('email', $request->email)
            ->orWhere('phone', $request->email)
            ->first();

        if (!$user || !Hash::check($request->password, $user->password)) {
            throw ValidationException::withMessages([
                'email' => ['Email/SĐT hoặc mật khẩu không đúng.'],
            ]);
        }

        $token = $user->createToken('api-token')->plainTextToken;

        return response()->json([
            'message' => 'Đăng nhập thành công',
            'access_token' => $token,
            'user' => $user,
        ]);
    }

    public function logout(Request $request): JsonResponse
    {
        $request->user()->currentAccessToken()->delete();

        return response()->json([
            'message' => 'Đăng xuất thành công',
        ]);
    }

    public function profile(Request $request): JsonResponse
    {
        return response()->json($request->user());
    }

    public function updateProfile(Request $request): JsonResponse
    {
        $user = $request->user();

        $data = $request->validate([
            'name' => 'nullable|string|max:255',
            'phone' => ['nullable', 'string', 'max:50', 'unique:users,phone,' . $user->id],
            'preferences' => 'sometimes|array|max:10',
            'preferences.*' => 'string|max:50',
            'address_line1' => 'nullable|string|max:255',
            'address_line2' => 'nullable|string|max:255',
            'city' => 'nullable|string|max:150',
            'state' => 'nullable|string|max:150',
            'postal_code' => 'nullable|string|max:20',
            'country' => 'nullable|string|max:150',
        ]);

        $payload = array_filter(
            $data,
            fn ($value) => !is_null($value)
        );

        if (array_key_exists('preferences', $payload)) {
            $payload['preferences'] = $this->sanitizePreferences($payload['preferences']);
        }

        $user->fill($payload);
        $user->save();

        return response()->json([
            'message' => 'Cập nhật hồ sơ thành công.',
            'user' => $user->fresh(),
        ]);
    }

    public function forgotPassword(Request $request): JsonResponse
    {
        $request->validate([
            'type' => 'required|in:email,phone',
            'value' => 'required',
        ]);

        $type = $request->type;
        $value = $request->value;

        $user = User::where($type, $value)->first();

        if (!$user) {
            return response()->json(['message' => 'Tài khoản không tồn tại'], 404);
        }

        $otp = rand(100000, 999999);

        DB::table('password_resets')->updateOrInsert(
            [$type => $value],
            [
                'token' => $otp,
                'created_at' => now(),
            ]
        );

        if ($type === 'email') {
            Mail::raw("Mã OTP đặt lại mật khẩu của bạn là: $otp", function ($message) use ($value) {
                $message->to($value)->subject('OTP Quên mật khẩu');
            });
        } else {
            logger("Gửi OTP $otp tới số điện thoại $value (giả lập)");
        }

        return response()->json([
            'message' => 'Đã gửi mã xác nhận thành công',
        ]);
    }

    public function verifyOtp(Request $request): JsonResponse
    {
        $request->validate([
            'type' => 'required|in:email,phone',
            'value' => 'required',
            'otp' => 'required',
        ]);

        $record = DB::table('password_resets')
            ->where($request->type, $request->value)
            ->where('token', $request->otp)
            ->first();

        if (!$record || now()->diffInMinutes($record->created_at) > 15) {
            return response()->json(['message' => 'Mã OTP không đúng hoặc đã hết hạn'], 422);
        }

        return response()->json([
            'message' => 'OTP hợp lệ, bạn có thể đặt lại mật khẩu.',
        ]);
    }

    public function resetPassword(Request $request): JsonResponse
    {
        $request->validate([
            'type' => 'required|in:email,phone',
            'value' => 'required',
            'new_password' => 'required|min:6|confirmed',
        ]);

        User::where($request->type, $request->value)->update([
            'password' => bcrypt($request->new_password),
        ]);

        DB::table('password_resets')->where($request->type, $request->value)->delete();

        return response()->json(['message' => 'Đặt lại mật khẩu thành công']);
    }

    private function sanitizePreferences(array $preferences): array
    {
        return collect($preferences)
            ->filter(fn ($item) => is_string($item) && trim($item) !== '')
            ->map(fn ($item) => mb_substr(trim($item), 0, 50))
            ->unique()
            ->take(10)
            ->values()
            ->all();
    }
}
