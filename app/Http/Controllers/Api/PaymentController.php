<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Payment;
use App\Services\SepayService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class PaymentController extends Controller
{
    private SepayService $sepay;

    public function __construct(SepayService $sepay)
    {
        $this->sepay = $sepay;
    }

    public function handleSepayWebhook(Request $request): JsonResponse
    {
        $payload = $request->all();
        $this->sepay->log('Webhook received', $payload);

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
}
