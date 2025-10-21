<?php

namespace App\Listeners;

use App\Models\Booking;
use Illuminate\Support\Facades\Log;
use SePay\SePay\Events\SePayWebhookEvent;

class SepayTransferListener
{
    public function handle(SePayWebhookEvent $event): void
    {
        $data = $event->sePayWebhookData;

        if (($data->transferType ?? '') !== 'in') {
            return;
        }

        $bookingId = $event->info;

        if (!$bookingId) {
            $content = (string) ($data->description ?? $data->content ?? '');
            if (preg_match('/BOOKING-([a-f0-9\-]+)/i', $content, $matches)) {
                $bookingId = $matches[1];
            } else {
                Log::info('[Sepay QR] Không tìm thấy mã booking trong nội dung giao dịch.', ['content' => $content]);
                return;
            }
        }

        $booking = Booking::with('payments')->find($bookingId);

        if (!$booking) {
            Log::warning('[Sepay QR] Booking không tồn tại.', ['booking_id' => $bookingId]);
            return;
        }

        $expectedAmount = (int) round($booking->total_price ?? 0);
        $actualAmount = (int) ($data->transferAmount ?? 0);

        if ($expectedAmount > 0 && $actualAmount !== $expectedAmount) {
            Log::warning('[Sepay QR] Số tiền không khớp.', [
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
}
