<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Booking;
use App\Models\Payment;
use App\Services\SepayService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use SePay\SePay\Http\Controllers\SePayController;

class PaymentController extends Controller
{
    private SepayService $sepay;

    public function __construct(SepayService $sepay)
    {
        $this->sepay = $sepay;
    }

    public function handleSepayWebhook(Request $request): JsonResponse|Response
    {
        $payload = $request->all();
        $this->sepay->log('Webhook received', $payload);

        if ($this->isBankTransferPayload($payload)) {
            return app(SePayController::class)->webhook($request);
        }

        if (!$this->sepay->verifySignature($payload)) {
            Log::warning('[Sepay] Invalid signature', ['payload' => $payload]);
            return response()->json(['message' => 'Invalid signature'], 400);
        }

        $paymentId = $payload['order_code'] ?? $payload['payment_id'] ?? null;
        if (!$paymentId) {
            return response()->json(['message' => 'Missing payment reference'], 400);
        }

        $payment = Payment::query()->findOrFail($paymentId);
        $service = $this->sepay;

        DB::transaction(function () use ($payload, $payment, $service) {
            $status = $service->extractStatus($payload);

            $booking = $payment->booking()->lockForUpdate()->first();
            if (!$booking) {
                return;
            }

            if ($status === 'success') {
                $payment->status = 'success';
                $payment->transaction_code = $payload['transaction_code'] ?? $payment->transaction_code;
                $payment->invoice_number = $payment->invoice_number ?? ($payload['invoice_no'] ?? null);
                $payment->paid_at = now();
                $payment->save();

                $booking->payment_status = 'paid';
                $booking->save();

                return;
            }

            if ($status === 'pending') {
                $payment->status = 'pending';
                $payment->save();

                $booking->payment_status = 'pending';
                $booking->save();

                return;
            }

            $payment->status = 'failed';
            $payment->save();

            if ($booking->payment_status !== 'refunded') {
                $booking->payment_status = 'unpaid';
                $booking->save();
            }
        });

        return response()->json([
            'message' => 'Webhook processed successfully.',
            'payment_id' => $payment->id,
        ]);
    }

    public function handleSepayReturn(Request $request): JsonResponse
    {
        $payload = $request->all();

        if (!$this->sepay->verifySignature($payload)) {
            return response()->json(['message' => 'Invalid signature'], 400);
        }

        $paymentId = $payload['order_code'] ?? $payload['payment_id'] ?? null;
        $status = $this->sepay->extractStatus($payload);

        $payment = $paymentId ? Payment::query()->find($paymentId) : null;

        return response()->json([
            'status' => $status,
            'payment_id' => $payment?->id,
            'booking_id' => $payment?->booking_id,
        ]);
    }

    public function status(Request $request, string $bookingId): JsonResponse
    {
        $booking = Booking::with(['payments' => function ($query) {
            $query->latest();
        }])->where('user_id', $request->user()->id)->findOrFail($bookingId);

        $latestPayment = $booking->payments->first();

        $status = $booking->payment_status;
        $message = match ($status) {
            'paid' => 'Thanh toán thành công.',
            'pending' => 'Thanh toán đang chờ xác nhận.',
            'failed' => 'Thanh toán thất bại. Vui lòng thử lại.',
            'refunded' => 'Đơn hàng đã được hoàn tiền.',
            default => 'Đơn hàng chưa được thanh toán.',
        };

        return response()->json([
            'booking_id' => $booking->id,
            'status' => $status,
            'message' => $message,
            'payment' => $latestPayment ? [
                'id' => $latestPayment->id,
                'method' => $latestPayment->method,
                'status' => $latestPayment->status,
                'amount' => $latestPayment->amount,
                'paid_at' => optional($latestPayment->paid_at)->toIso8601String(),
            ] : null,
        ]);
    }

    public function payLater(Request $request, string $bookingId): JsonResponse
    {
        $booking = Booking::with('payments', 'user')
            ->where('user_id', $request->user()->id)
            ->findOrFail($bookingId);

        if ($booking->status === 'cancelled') {
            return response()->json([
                'message' => 'Booking has been cancelled and cannot be paid.',
            ], 422);
        }

        if (in_array($booking->payment_status, ['paid', 'refunded'], true)) {
            return response()->json([
                'message' => 'This booking does not require additional payment.',
            ], 422);
        }

        if (!$this->sepay->isEnabled() && !$this->sepay->hasStaticQrConfig()) {
            return response()->json([
                'message' => 'Sepay is not configured. Please contact support.',
            ], 422);
        }

        $paidAmount = $booking->payments
            ->where('status', 'success')
            ->sum(function (Payment $payment) {
                return (float) $payment->amount;
            });

        $outstanding = round(max(0, ($booking->total_price ?? 0) - $paidAmount), 2);

        if ($outstanding <= 0) {
            return response()->json([
                'message' => 'Booking balance is already settled.',
            ], 422);
        }

        $payment = Payment::create([
            'booking_id' => $booking->id,
            'method' => 'sepay',
            'amount' => $outstanding,
            'tax' => 0,
            'status' => 'pending',
        ]);

        $paymentUrl = null;
        $paymentQrUrl = null;
        $notifyUrl = route('payments.sepay.webhook');

        if ($this->sepay->isEnabled()) {
            $paymentUrl = $this->sepay->createPaymentLink($payment, $booking, $notifyUrl, config('sepay.return_url'));
        } else {
            $paymentQrUrl = $this->sepay->buildStaticQrUrl($booking, $payment);
            $paymentUrl = $paymentQrUrl;
        }

        $booking->payment_status = 'pending';
        $booking->save();

        return response()->json([
            'message' => 'Payment link generated successfully.',
            'payment' => [
                'id' => $payment->id,
                'method' => $payment->method,
                'status' => $payment->status,
                'amount' => $payment->amount,
            ],
            'payment_url' => $paymentUrl,
            'payment_qr_url' => $paymentQrUrl,
        ]);
    }

    private function isBankTransferPayload(array $payload): bool
    {
        return isset($payload['transferAmount'], $payload['transferType']) &&
            (array_key_exists('content', $payload) || array_key_exists('description', $payload));
    }
}
