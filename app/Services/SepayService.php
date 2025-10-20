<?php

namespace App\Services;

use App\Models\Booking;
use App\Models\Payment;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Log;

class SepayService
{
    private string $merchantCode;
    private string $apiKey;
    private string $checksumKey;
    private string $paymentUrl;

    public function __construct()
    {
        $this->merchantCode = (string) (config('sepay.merchant_code') ?? '');
        $this->apiKey = (string) (config('sepay.api_key') ?? '');
        $this->checksumKey = (string) (config('sepay.checksum_key') ?? '');
        $this->paymentUrl = rtrim((string) (config('sepay.payment_url') ?? ''), '/');
    }

    public function isEnabled(): bool
    {
        return $this->merchantCode !== '' && $this->apiKey !== '' && $this->checksumKey !== '' && $this->paymentUrl !== '';
    }

    public function createPaymentLink(Payment $payment, Booking $booking, string $notifyUrl, ?string $returnUrl = null): string
    {
        $payload = [
            'merchant_code' => $this->merchantCode,
            'api_key' => $this->apiKey,
            'order_code' => $payment->id,
            'amount' => (int) round($payment->amount),
            'description' => sprintf('Thanh toan booking %s', $booking->id),
            'buyer_name' => $booking->contact_name ?? $booking->user?->name,
            'buyer_email' => $booking->contact_email ?? $booking->user?->email,
            'buyer_phone' => $booking->contact_phone ?? null,
            'return_url' => $returnUrl ?? config('sepay.return_url'),
            'notify_url' => $notifyUrl,
        ];

        $payload = array_filter($payload, static fn ($value) => !is_null($value) && $value !== '');
        $payload['signature'] = $this->buildSignature($payload);

        return sprintf('%s?%s', $this->paymentUrl, http_build_query($payload));
    }

    public function verifySignature(array $data): bool
    {
        $signature = Arr::get($data, 'signature');
        if (!$signature) {
            return false;
        }

        $calculated = $this->buildSignature(Arr::except($data, ['signature']));

        return hash_equals($calculated, $signature);
    }

    public function extractStatus(array $data): string
    {
        $status = strtolower((string) Arr::get($data, 'status', ''));

        return match ($status) {
            'success', 'completed', 'paid' => 'success',
            'pending', 'processing' => 'pending',
            default => 'failed',
        };
    }

    private function buildSignature(array $data): string
    {
        ksort($data);
        $encoded = urldecode(http_build_query($data));

        return hash_hmac('sha256', $encoded, $this->checksumKey);
    }

    public function log(string $message, array $context = []): void
    {
        Log::channel(config('sepay.log_channel', 'stack'))->info('[Sepay] ' . $message, $context);
    }
}
