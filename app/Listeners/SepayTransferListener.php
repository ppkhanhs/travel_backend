<?php

namespace App\Listeners;

use App\Models\Booking;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use SePay\SePay\Events\SePayWebhookEvent;

class SepayTransferListener
{
    public function handle(SePayWebhookEvent $event): void
    {
        $data = $event->sePayWebhookData;

        if (($data->transferType ?? '') !== 'in') {
            return;
        }

        $bookingId = $this->extractBookingId(
            $event->info ?? null,
            $data->description ?? null,
            $data->content ?? null
        );

        if (!$bookingId || !Str::isUuid($bookingId)) {
            Log::warning('[Sepay QR] Không tìm thấy mã booking hợp lệ trong nội dung webhook.', [
                'info' => $event->info ?? null,
                'description' => $data->description ?? null,
                'content' => $data->content ?? null,
            ]);
            return;
        }

        $booking = Booking::with('payments')->find($bookingId);

        if (!$booking) {
            Log::warning('[Sepay QR] Booking không tồn tại.', ['booking_id' => $bookingId]);
            return;
        }

        $expectedAmount = (int) round($booking->total_price ?? 0);
        $actualAmount = (int) ($data->transferAmount ?? 0);

        if ($expectedAmount > 0 && $actualAmount !== $expectedAmount) {
            Log::warning('[Sepay QR] Số tiền chuyển không khớp.', [
                'booking_id' => $bookingId,
                'expected' => $expectedAmount,
                'actual' => $actualAmount,
            ]);
            return;
        }

        $reference = $data->referenceCode ?? null;

        $booking->payments
            ->where('method', 'sepay')
            ->where('status', 'pending')
            ->each(function ($payment) use ($actualAmount, $reference) {
                $payment->status = 'success';
                $payment->amount = $payment->amount ?: $actualAmount;
                $payment->transaction_code = $payment->transaction_code ?? $reference;
                $payment->paid_at = now();
                $payment->save();
            });

        $booking->payment_status = 'paid';
        $booking->save();
    }

    private function extractBookingId(?string ...$sources): ?string
    {
        foreach ($sources as $source) {
            if (!$source) {
                continue;
            }

            if (preg_match('/([0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12})/i', $source, $matches)) {
                return Str::lower($matches[1]);
            }

            if (preg_match('/([0-9a-f]{32})/i', $source, $matches)) {
                $hex = strtolower($matches[1]);

                return sprintf(
                    '%s-%s-%s-%s-%s',
                    substr($hex, 0, 8),
                    substr($hex, 8, 4),
                    substr($hex, 12, 4),
                    substr($hex, 16, 4),
                    substr($hex, 20, 12)
                );
            }
        }

        return null;
    }
}
