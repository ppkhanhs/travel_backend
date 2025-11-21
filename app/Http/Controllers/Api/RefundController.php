<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\BookingResource;
use App\Models\Booking;
use App\Models\RefundRequest;
use App\Notifications\RefundRequestCreatedNotification;
use App\Notifications\RefundRequestUpdatedNotification;
use App\Notifications\AdminBookingNotification;
use App\Services\NotificationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class RefundController extends Controller
{
    public function __construct(private NotificationService $notifications)
    {
    }
    public function index(Request $request): JsonResponse
    {
        $requests = RefundRequest::query()
            ->with(['booking.tourSchedule.tour', 'booking.promotions'])
            ->where('user_id', $request->user()->id)
            ->when($request->filled('status'), fn ($q) => $q->where('status', $request->status))
            ->orderByDesc('created_at')
            ->paginate($request->integer('per_page', 15));

        return response()->json($requests);
    }

    public function store(Request $request, string $bookingId): JsonResponse
    {
        $data = $request->validate([
            'bank_account_name' => 'required|string|max:255',
            'bank_account_number' => 'required|string|max:50',
            'bank_name' => 'required|string|max:255',
            'bank_branch' => 'nullable|string|max:255',
            'customer_message' => 'nullable|string|max:1000',
        ]);

        $booking = Booking::with(['payments', 'refundRequests'])
            ->where('user_id', $request->user()->id)
            ->findOrFail($bookingId);

        if ($booking->status !== 'cancelled' || $booking->payment_status !== 'paid') {
            throw ValidationException::withMessages([
                'booking' => ['Only cancelled and paid bookings can request refunds.'],
            ]);
        }

        $hasPending = $booking->refundRequests()
            ->whereIn('status', ['pending_partner', 'await_customer_confirm'])
            ->exists();

        if ($hasPending) {
            throw ValidationException::withMessages([
                'booking' => ['An existing refund request is already in progress.'],
            ]);
        }

        if (strcasecmp($data['bank_account_name'], $booking->contact_name ?? '') !== 0) {
            throw ValidationException::withMessages([
                'bank_account_name' => ['Account name must match booking contact name.'],
            ]);
        }

        $amount = (float) ($booking->payments
            ->where('status', 'success')
            ->sum(fn ($payment) => $payment->amount ?? 0));

        if ($amount <= 0) {
            throw ValidationException::withMessages([
                'booking' => ['No successful payments found to refund.'],
            ]);
        }

        $partnerId = optional(optional($booking->tourSchedule)->tour?->partner)->id;
        if (!$partnerId) {
            throw ValidationException::withMessages([
                'booking' => ['Unable to determine partner for this booking.'],
            ]);
        }

        $refund = RefundRequest::create([
            'booking_id' => $booking->id,
            'user_id' => $booking->user_id,
            'partner_id' => $partnerId,
            'amount' => $amount,
            'currency' => 'VND',
            'bank_account_name' => $data['bank_account_name'],
            'bank_account_number' => $data['bank_account_number'],
            'bank_name' => $data['bank_name'],
            'bank_branch' => $data['bank_branch'] ?? null,
            'customer_message' => $data['customer_message'] ?? null,
            'status' => 'pending_partner',
        ]);

        $refund->load(['partner.user']);
        $this->notifications->notify($refund->partner?->user, new RefundRequestCreatedNotification($refund));
        $this->notifications->notifyAdmins(new AdminBookingNotification($booking, 'refund_requested'));

        return response()->json([
            'message' => 'Refund request submitted successfully.',
            'refund_request' => $refund->fresh(),
        ], 201);
    }

    public function confirm(Request $request, string $id): JsonResponse
    {
        $refund = RefundRequest::query()
            ->where('id', $id)
            ->where('user_id', $request->user()->id)
            ->firstOrFail();

        if ($refund->status !== 'await_customer_confirm') {
            throw ValidationException::withMessages([
                'refund' => ['This refund cannot be confirmed at the current status.'],
            ]);
        }

        DB::transaction(function () use ($refund) {
            $refund->status = 'completed';
            $refund->customer_confirmed_at = now();
            $refund->save();

            $booking = Booking::query()
                ->with('payments')
                ->lockForUpdate()
                ->find($refund->booking_id);

            if ($booking) {
                $booking->payment_status = 'refunded';
                $booking->save();

                $remaining = max(0, (float) ($refund->amount ?? 0));

                foreach ($booking->payments as $payment) {
                    if (!in_array($payment->status, ['success', 'pending'], true)) {
                        continue;
                    }

                    $baseAmount = (float) ($payment->total_amount ?? $payment->amount ?? 0);
                    if ($baseAmount <= 0) {
                        continue;
                    }

                    $applied = $remaining > 0 ? min($baseAmount, $remaining) : $baseAmount;

                    $payment->status = 'refunded';
                    $payment->refund_amount = round($applied, 2);
                    $payment->save();

                    $remaining = max(0, $remaining - $applied);
                }
            }
        });

        $this->notifications->notify($refund->partner?->user, new RefundRequestUpdatedNotification($refund));
        $booking = $refund->booking ?? Booking::find($refund->booking_id);
        if ($booking) {
            $this->notifications->notifyAdmins(new AdminBookingNotification($booking, 'refund_requested'));
        }

        return response()->json([
            'message' => 'Thank you! Refund has been confirmed.',
            'refund_request' => $refund,
        ]);
    }
}
