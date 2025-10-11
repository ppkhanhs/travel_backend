<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\SocialAccount;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Laravel\Socialite\Facades\Socialite;

class SocialAuthController extends Controller
{
    private const PROVIDERS = ['google', 'facebook'];

    // Điều hướng người dùng tới trang đăng nhập của nhà cung cấp
    public function redirect(Request $request, string $provider)
    {
        $this->guardProvider($provider);

        /** @var \Laravel\Socialite\Contracts\Provider|\Laravel\Socialite\Two\AbstractProvider $driver */
        $driver = Socialite::driver($provider);

        if (method_exists($driver, 'stateless')) {
            $driver = $driver->stateless();
        }

        if ($provider === 'google' && $driver instanceof \Laravel\Socialite\Two\GoogleProvider) {
            $driver = $driver->with(['prompt' => 'select_account']);
        }

        if ($request->wantsJson()) {
            return response()->json([
                'url' => $driver->redirect()->getTargetUrl(),
            ]);
        }

        return $driver->redirect();
    }

    // Nhận callback từ provider, liên kết/tạo user và phát hành token
    public function callback(Request $request, string $provider)
    {
        $this->guardProvider($provider);

        try {
            /** @var \Laravel\Socialite\Contracts\Provider|\Laravel\Socialite\Two\AbstractProvider $driver */
            $driver = Socialite::driver($provider);

            if (method_exists($driver, 'stateless')) {
                $driver = $driver->stateless();
            }

            if ($provider === 'google' && $driver instanceof \Laravel\Socialite\Two\GoogleProvider) {
                $driver = $driver->with(['prompt' => 'select_account']);
            }

            $socialUser = $driver->user();
        } catch (\Throwable $e) {
            Log::error('Social login error', ['provider' => $provider, 'error' => $e->getMessage()]);
            throw ValidationException::withMessages([
                'provider' => ['Không thể xác thực với ' . ucfirst($provider) . '. Vui lòng thử lại.'],
            ]);
        }

        $account = SocialAccount::where('provider', $provider)
            ->where('provider_user_id', $socialUser->getId())
            ->first();

        if ($account) {
            $user = $account->user;
        } else {
            DB::beginTransaction();
            try {
                $user = $this->findOrCreateUser($socialUser->getEmail(), $socialUser->getName());

                $account = SocialAccount::create([
                    'user_id' => $user->id,
                    'provider' => $provider,
                    'provider_user_id' => $socialUser->getId(),
                    'email' => $socialUser->getEmail(),
                    'access_token' => $socialUser->token,
                    'refresh_token' => $socialUser->refreshToken ?? null,
                    'token_expires_at' => isset($socialUser->expiresIn)
                        ? now()->addSeconds($socialUser->expiresIn)
                        : null,
                    'meta' => [
                        'name' => $socialUser->getName(),
                        'avatar' => $socialUser->getAvatar(),
                    ],
                ]);

                DB::commit();
            } catch (\Throwable $e) {
                DB::rollBack();
                Log::error('Social login create error', ['provider' => $provider, 'error' => $e->getMessage()]);
                throw ValidationException::withMessages([
                    'provider' => ['Không thể tạo tài khoản từ ' . ucfirst($provider) . '.'],
                ]);
            }
        }

        $token = $user->createToken('api-token')->plainTextToken;

        $payload = [
            'message' => 'Đăng nhập thành công.',
            'access_token' => $token,
            'token_type' => 'Bearer',
            'user' => $user->fresh(),
            'provider' => $provider,
        ];

        $redirect = config('services.oauth_redirect');
        if ($redirect) {
            $query = http_build_query([
                'token' => $token,
                'provider' => $provider,
                'status' => 'success',
            ]);

            return redirect()->away(rtrim($redirect, '/') . '?' . $query);
        }

        return response()->json($payload);
    }

    private function findOrCreateUser(?string $email, ?string $name): User
    {
        $user = null;

        if ($email) {
            $user = User::where('email', $email)->first();
        }

        if ($user) {
            if ($email && !$user->email_verified_at) {
                $user->email_verified_at = now();
                $user->save();
            }
            return $user;
        }

        return User::create([
            'name' => $name ?: 'Khách ' . Str::random(6),
            'email' => $email ?: $this->buildPlaceholderEmail(),
            'phone' => null,
            'role' => 'customer',
            'password' => null,
        ]);
    }

    private function buildPlaceholderEmail(): string
    {
        return sprintf('%s@social.local', Str::uuid());
    }

    private function guardProvider(string $provider): void
    {
        if (!in_array($provider, self::PROVIDERS, true)) {
            abort(404);
        }
    }
}
