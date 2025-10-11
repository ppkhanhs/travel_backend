<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class AuthOtpController extends Controller
{
    private const OTP_TTL_MINUTES = 5;
    private const OTP_RESEND_SECONDS = 60;
    private const OTP_MAX_ATTEMPTS = 5;
    private const PASSWORD_GRACE_MINUTES = 30;

    // Gửi OTP qua email hoặc số điện thoại
    public function send(Request $request): JsonResponse
    {
        $data = $request->validate([
            'channel' => 'required|in:email,phone',
            'value' => ['required', 'string', function ($attribute, $value, $fail) use ($request) {
                if ($request->channel === 'email' && !filter_var($value, FILTER_VALIDATE_EMAIL)) {
                    $fail('Định dạng email không hợp lệ.');
                }
                if ($request->channel === 'phone' && !preg_match('/^[0-9\+\-\s]{6,20}$/', $value)) {
                    $fail('Định dạng số điện thoại không hợp lệ.');
                }
            }],
        ]);

        $channel = $data['channel'];
        $value = trim($data['value']);

        $rateKey = sprintf('send-otp:%s', hash('sha256', $channel . '|' . $value . '|' . $request->ip()));
        if (RateLimiter::tooManyAttempts($rateKey, 5)) {
            $seconds = RateLimiter::availableIn($rateKey);
            throw ValidationException::withMessages([
                'value' => ["Bạn đã yêu cầu mã OTP quá nhiều lần. Vui lòng thử lại sau {$seconds} giây."],
            ]);
        }
        RateLimiter::hit($rateKey, self::OTP_RESEND_SECONDS);

        $existing = DB::table('user_otps')
            ->where('channel', $channel)
            ->where('value', $value)
            ->first();

        if ($existing) {
            $lastSent = Carbon::parse($existing->updated_at);
            if ($lastSent->diffInSeconds(now()) < self::OTP_RESEND_SECONDS) {
                $wait = self::OTP_RESEND_SECONDS - $lastSent->diffInSeconds(now());
                throw ValidationException::withMessages([
                    'value' => ["Vui lòng chờ thêm {$wait} giây trước khi yêu cầu lại."],
                ]);
            }
        }

        $otpCode = (string) random_int(100000, 999999);
        $expires = now()->addMinutes(self::OTP_TTL_MINUTES);
        $sentBy = $channel === 'email' ? 'email' : 'sms';

        if ($existing) {
            DB::table('user_otps')
                ->where('id', $existing->id)
                ->update([
                    'otp' => $otpCode,
                    'attempts' => 0,
                    'expires_at' => $expires,
                    'verified_at' => null,
                    'sent_by' => $sentBy,
                    'ip_address' => $request->ip(),
                    'updated_at' => now(),
                ]);
            $otpId = $existing->id;
        } else {
            $otpId = (string) Str::uuid();
            DB::table('user_otps')->insert([
                'id' => $otpId,
                'channel' => $channel,
                'value' => $value,
                'otp' => $otpCode,
                'attempts' => 0,
                'expires_at' => $expires,
                'verified_at' => null,
                'sent_by' => $sentBy,
                'ip_address' => $request->ip(),
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        if ($channel === 'email') {
            $this->sendEmailOtp($value, $otpCode);
        } else {
            logger()->info("[OTP] gửi SMS", ['phone' => $value, 'otp' => $otpCode]);
        }

        $response = [
            'message' => 'Đã gửi mã xác thực.',
            'otp_id' => $otpId,
            'expired_at' => $expires->toIso8601String(),
        ];

        if (config('app.debug')) {
            $response['debug_otp'] = $otpCode;
        }

        return response()->json($response);
    }

    // Xác thực OTP và tạo tài khoản nếu chưa tồn tại
    public function verify(Request $request): JsonResponse
    {
        $data = $request->validate([
            'channel' => 'required|in:email,phone',
            'value' => 'required|string',
            'otp' => 'required|string|max:10',
        ]);

        $channel = $data['channel'];
        $value = trim($data['value']);
        $otpCode = trim($data['otp']);

        $record = DB::table('user_otps')
            ->where('channel', $channel)
            ->where('value', $value)
            ->first();

        if (!$record) {
            throw ValidationException::withMessages([
                'otp' => ['Mã OTP không hợp lệ hoặc đã hết hạn.'],
            ]);
        }

        if (Carbon::parse($record->expires_at)->isPast()) {
            throw ValidationException::withMessages([
                'otp' => ['Mã OTP đã hết hạn, vui lòng yêu cầu mã mới.'],
            ]);
        }

        if ($record->attempts >= self::OTP_MAX_ATTEMPTS) {
            throw ValidationException::withMessages([
                'otp' => ['Bạn đã nhập sai OTP quá số lần cho phép.'],
            ]);
        }

        if (!hash_equals($record->otp, $otpCode)) {
            DB::table('user_otps')
                ->where('id', $record->id)
                ->update([
                    'attempts' => $record->attempts + 1,
                    'updated_at' => now(),
                ]);

            throw ValidationException::withMessages([
                'otp' => ['Mã OTP không chính xác.'],
            ]);
        }

        DB::table('user_otps')
            ->where('id', $record->id)
            ->update([
                'attempts' => $record->attempts + 1,
                'verified_at' => now(),
                'updated_at' => now(),
            ]);

        $user = $this->findOrCreateUserFromOtp($channel, $value);

        $needsPassword = empty($user->password);

        if ($channel === 'email') {
            $user->email_verified_at = now();
        } else {
            $user->phone_verified_at = now();
        }
        $user->save();

        if ($needsPassword) {
            return response()->json([
                'message' => 'Xác thực thành công. Vui lòng thiết lập mật khẩu.',
                'status' => 'need_password',
                'otp_id' => $record->id,
                'user' => $user,
            ]);
        }

        DB::table('user_otps')->where('id', $record->id)->delete();

        $token = $user->createToken('api-token')->plainTextToken;

        return response()->json([
            'message' => 'Đăng nhập thành công.',
            'access_token' => $token,
            'token_type' => 'Bearer',
            'user' => $user,
        ]);
    }

    // Thiết lập mật khẩu sau khi xác thực OTP thành công
    public function setPassword(Request $request): JsonResponse
    {
        $data = $request->validate([
            'otp_id' => 'required|uuid',
            'channel' => 'required|in:email,phone',
            'value' => 'required|string',
            'otp' => 'required|string|max:10',
            'password' => 'required|string|min:6|confirmed',
        ]);

        $record = DB::table('user_otps')
            ->where('id', $data['otp_id'])
            ->where('channel', $data['channel'])
            ->where('value', trim($data['value']))
            ->first();

        if (!$record || !$record->verified_at) {
            throw ValidationException::withMessages([
                'otp' => ['Phiên đặt mật khẩu không hợp lệ. Vui lòng xác thực OTP lại.'],
            ]);
        }

        if (Carbon::parse($record->verified_at)->diffInMinutes(now()) > self::PASSWORD_GRACE_MINUTES) {
            throw ValidationException::withMessages([
                'otp' => ['Phiên đặt mật khẩu đã hết hạn. Vui lòng xác thực lại.'],
            ]);
        }

        if (!hash_equals($record->otp, $data['otp'])) {
            throw ValidationException::withMessages([
                'otp' => ['Mã OTP không chính xác.'],
            ]);
        }

        $user = $this->findUserByChannel($data['channel'], trim($data['value']));

        if (!$user) {
            throw ValidationException::withMessages([
                'value' => ['Không tìm thấy tài khoản tương ứng.'],
            ]);
        }

        $user->password = Hash::make($data['password']);
        if ($data['channel'] === 'email') {
            $user->email_verified_at = now();
        } else {
            $user->phone_verified_at = now();
        }
        $user->save();

        DB::table('user_otps')->where('id', $record->id)->delete();

        $token = $user->createToken('api-token')->plainTextToken;

        return response()->json([
            'message' => 'Thiết lập mật khẩu thành công.',
            'access_token' => $token,
            'token_type' => 'Bearer',
            'user' => $user,
        ]);
    }

    private function findOrCreateUserFromOtp(string $channel, string $value): User
    {
        $user = $this->findUserByChannel($channel, $value);

        if ($user) {
            return $user;
        }

        $placeholderName = $channel === 'email'
            ? strstr($value, '@', true) ?: 'Khách'
            : 'Khách ' . substr($value, -4);

        $attributes = [
            'name' => $placeholderName,
            'role' => 'customer',
        ];

        if ($channel === 'email') {
            $attributes['email'] = $value;
            $attributes['phone'] = null;
        } else {
            $attributes['phone'] = $value;
            $attributes['email'] = $this->buildPlaceholderEmail($value);
        }

        return User::create($attributes);
    }

    private function findUserByChannel(string $channel, string $value): ?User
    {
        return User::where($channel, $value)->first();
    }

    private function buildPlaceholderEmail(string $phone): string
    {
        $normalized = preg_replace('/\D+/', '', $phone) ?: Str::uuid();
        return sprintf('%s@phone.local', $normalized . Str::random(4));
    }
    private function sendEmailOtp(string $recipient, string $otpCode): void
    {
        $brevo = config('services.brevo');
        $apiKey = $brevo['api_key'] ?? null;
        $senderEmail = $brevo['sender_email'] ?? null;
        $senderName = $brevo['sender_name'] ?? config('app.name');

        if (!$apiKey || !$senderEmail) {
            logger()->error('[OTP] Brevo credentials missing', ['sender' => $senderEmail]);
            throw ValidationException::withMessages([
                'value' => ['Không thể gửi email xác thực. Vui lòng thử lại sau.'],
            ]);
        }

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
                ['email' => $recipient],
            ],
            'subject' => 'Mã xác thực OTP',
            'htmlContent' => "<p>Mã OTP của bạn là: <strong>{$otpCode}</strong>. Mã có hiệu lực trong " . self::OTP_TTL_MINUTES . " phút.</p>",
        ]);

        if ($response->failed()) {
            logger()->error('[OTP] Brevo API failed', [
                'status' => $response->status(),
                'body' => $response->body(),
            ]);

            throw ValidationException::withMessages([
                'value' => ['Không thể gửi email xác thực. Vui lòng thử lại sau.'],
            ]);
        }
    }
}
