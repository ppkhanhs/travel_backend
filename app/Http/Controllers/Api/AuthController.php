<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use App\Models\User;
use Illuminate\Validation\ValidationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Validator;

class AuthController extends Controller
{
    // 1. Đăng ký
    public function register(Request $request)
    {
        // validate + tạo user
        $user = User::create([
            'name' => $request->name,
            'email' => $request->email,
            'phone' => $request->phone,
            'password' => bcrypt($request->password),
            'role' => 'customer',
        ]);

        return response()->json([
            'message' => 'Đăng ký thành công',
            'user' => $user
        ], 201);
    }

    // 2. Đăng nhập
    public function login(Request $request)
    {
        $request->validate([
            'email' => 'required',
            'password' => 'required'
        ]);

        $user = User::where('email', $request->email)
            ->orWhere('phone', $request->email)
            ->first();

        if (!$user || !Hash::check($request->password, $user->password)) {
            throw ValidationException::withMessages([
                'email' => ['Email/Số điện thoại hoặc mật khẩu không đúng.'],
            ]);
        }

        $token = $user->createToken('api-token')->plainTextToken;

        return response()->json([
            'message' => 'Đăng nhập thành công',
            'access_token' => $token,
            'user' => $user
        ]);
    }

    // 3. Đăng xuất
    public function logout(Request $request)
    {
        $request->user()->currentAccessToken()->delete();

        return response()->json([
            'message' => 'Đăng xuất thành công'
        ]);
    }

    // 4. Thông tin người dùng đang đăng nhập
    public function profile(Request $request)
    {
        return response()->json($request->user());
    }

    public function updateProfile(Request $request)
    {
        $user = $request->user();

        $data = $request->validate([
            'name' => 'nullable|string|max:255',
            'phone' => ['nullable', 'string', 'max:50', 'unique:users,phone,' . $user->id],
            'address_line1' => 'nullable|string|max:255',
            'address_line2' => 'nullable|string|max:255',
            'city' => 'nullable|string|max:150',
            'state' => 'nullable|string|max:150',
            'postal_code' => 'nullable|string|max:20',
            'country' => 'nullable|string|max:150',
        ]);

        $user->fill(array_filter(
            $data,
            fn ($value) => !is_null($value)
        ));
        $user->save();

        return response()->json([
            'message' => 'Cập nhật hồ sơ thành công.',
            'user' => $user->fresh(),
        ]);
    }

    public function forgotPassword(Request $request)
    {
        $request->validate([
            'type' => 'required|in:email,phone',
            'value' => 'required'
        ]);

        $type = $request->type;
        $value = $request->value;

        // Tìm user theo email hoặc phone
        $user = User::where($type, $value)->first();

        if (!$user) {
            return response()->json(['message' => 'Tài khoản không tồn tại'], 404);
        }

        // Tạo mã OTP ngẫu nhiên
        $otp = rand(100000, 999999);

        // Lưu vào bảng password_resets
        DB::table('password_resets')->updateOrInsert(
            [$type => $value],
            [
                'token' => $otp,
                'created_at' => now()
            ]
        );

        if ($type === 'email') {
            Mail::raw("Mã OTP đặt lại mật khẩu của bạn là: $otp", function ($message) use ($value) {
                $message->to($value)->subject('OTP Quên mật khẩu');
            });
        } else {
            // Tích hợp dịch vụ SMS thực tế (ví dụ: Twilio/Nexmo)
            // SMS::send($value, "Mã OTP là: $otp");
            logger("GỬI OTP $otp tới số điện thoại $value (giả lập)");
        }

        return response()->json([
            'message' => 'Đã gửi mã xác nhận thành công'
        ]);
    }

    public function verifyOtp(Request $request)
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
            'message' => 'OTP hợp lệ, bạn có thể đặt lại mật khẩu.'
        ]);
    }

    public function resetPassword(Request $request)
    {
        $request->validate([
            'type' => 'required|in:email,phone',
            'value' => 'required',
            'new_password' => 'required|min:6|confirmed',
        ]);

        User::where($request->type, $request->value)->update([
            'password' => bcrypt($request->new_password)
        ]);

        DB::table('password_resets')->where($request->type, $request->value)->delete();

        return response()->json(['message' => 'Đặt lại mật khẩu thành công']);
    }
}
