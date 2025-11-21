<?php

namespace App\Http\Controllers\Api\Partner;

use App\Http\Controllers\Controller;
use App\Models\Booking;
use App\Models\TourSchedule;
use App\Notifications\BookingCancelledNotification;
use App\Notifications\BookingCompletedNotification;
use App\Notifications\BookingConfirmedNotification;
use App\Notifications\BookingReviewRequestNotification;
use App\Services\NotificationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

class BookingController extends Controller
{
    public function __construct(private NotificationService $notifications)
    {
    }

    public function index(Request $request): JsonResponse
    {
        $partner = $this->getAuthenticatedPartner();
        if (!$partner) {
            return response()->json(['message' => 'Partner account is not approved.'], 403);
        }

        $bookings = Booking::with(['tourSchedule.tour', 'package', 'passengers'])
            ->whereHas('tourSchedule.tour', static fn ($query) => $query->where('partner_id', $partner->id))
            ->when($request->filled('status'), static fn ($query) => $query->where('status', $request->status))
            ->orderByDesc('booking_date')
            ->paginate($request->integer('per_page', 20));

        return response()->json($bookings);
    }

    public function show(Request $request, string $id): JsonResponse
    {
        $partner = $this->getAuthenticatedPartner();
        if (!$partner) {
            return response()->json(['message' => 'Partner account is not approved.'], 403);
        }

        $booking = Booking::with([
                'tourSchedule.tour.partner',
                'package',
                'passengers',
                'payments',
                'promotions',
                'refundRequests',
                'user',
            ])
            ->whereHas('tourSchedule.tour', static fn ($query) => $query->where('partner_id', $partner->id))
            ->findOrFail($id);

        return response()->json([
            'data' => [
                'id' => $booking->id,
                'status' => $booking->status,
                'payment_status' => $booking->payment_status,
                'booking_date' => optional($booking->booking_date)->toIso8601String(),
                'total_price' => $booking->total_price,
                'total_adults' => $booking->total_adults,
                'total_children' => $booking->total_children,
                'contact' => [
                    'name' => $booking->contact_name,
                    'email' => $booking->contact_email,
                    'phone' => $booking->contact_phone,
                    'notes' => $booking->notes,
                ],
                'tour' => $booking->tourSchedule?->tour?->only(['id', 'title', 'destination', 'type']),
                'schedule' => $booking->tourSchedule ? [
                    'id' => $booking->tourSchedule->id,
                    'start_date' => optional($booking->tourSchedule->start_date)->toDateString(),
                    'end_date' => optional($booking->tourSchedule->end_date)->toDateString(),
                    'min_participants' => $booking->tourSchedule->min_participants,
                ] : null,
                'package' => $booking->package ? [
                    'id' => $booking->package->id,
                    'name' => $booking->package->name,
                    'adult_price' => (float) $booking->package->adult_price,
                    'child_price' => (float) $booking->package->child_price,
                ] : null,
                'passengers' => $booking->passengers->map(function ($passenger) {
                    return [
                        'id' => $passenger->id,
                        'type' => $passenger->type,
                        'full_name' => $passenger->full_name,
                        'gender' => $passenger->gender,
                        'date_of_birth' => optional($passenger->date_of_birth)->toDateString(),
                        'document_number' => $passenger->document_number,
                    ];
                }),
                'payments' => $booking->payments->map(function ($payment) {
                    $payable = max(0, ($payment->amount ?? 0) - ($payment->discount_amount ?? 0));

                    return [
                        'id' => $payment->id,
                        'method' => $payment->method,
                        'status' => $payment->status,
                        'amount' => $payment->amount,
                        'discount_amount' => $payment->discount_amount,
                        'payable_amount' => $payable,
                        'transaction_code' => $payment->transaction_code,
                        'invoice_number' => $payment->invoice_number,
                        'paid_at' => optional($payment->paid_at)->toIso8601String(),
                    ];
                }),
                'promotions' => $booking->promotions->map(function ($promotion) {
                    return [
                        'id' => $promotion->id,
                        'code' => $promotion->code,
                        'discount_type' => $promotion->pivot->discount_type ?? $promotion->discount_type,
                        'value' => $promotion->value,
                        'discount_amount' => (float) ($promotion->pivot->discount_amount ?? 0),
                    ];
                }),
                'refund_requests' => $booking->refundRequests->map(function ($refund) {
                    return [
                        'id' => $refund->id,
                        'status' => $refund->status,
                        'amount' => $refund->amount,
                        'currency' => $refund->currency,
                        'bank_account_name' => $refund->bank_account_name,
                        'bank_account_number' => $refund->bank_account_number,
                        'bank_name' => $refund->bank_name,
                        'bank_branch' => $refund->bank_branch,
                        'customer_message' => $refund->customer_message,
                        'partner_message' => $refund->partner_message,
                        'proof_url' => $refund->proof_url,
                        'partner_marked_at' => optional($refund->partner_marked_at)->toIso8601String(),
                        'customer_confirmed_at' => optional($refund->customer_confirmed_at)->toIso8601String(),
                        'created_at' => optional($refund->created_at)->toIso8601String(),
                    ];
                }),
            ],
        ]);
    }

    public function updateStatus(Request $request, string $id): JsonResponse
    {
        $partner = $this->getAuthenticatedPartner();
        if (!$partner) {
            return response()->json(['message' => 'Partner account is not approved.'], 403);
        }

        $data = $request->validate([
            'status' => 'required|in:pending,confirmed,cancelled,completed',
        ]);

        $booking = Booking::whereHas('tourSchedule.tour', static fn ($query) => $query->where('partner_id', $partner->id))
            ->with('tourSchedule')
            ->findOrFail($id);

        $currentStatus = $booking->status;
        $newStatus = $data['status'];

        if ($currentStatus === $newStatus) {
            return response()->json(['message' => 'Booking status is unchanged.']);
        }

        DB::transaction(function () use ($booking, $newStatus) {
            if ($newStatus === 'cancelled' && in_array($booking->status, ['pending', 'confirmed'], true)) {
                $schedule = TourSchedule::where('id', $booking->tour_schedule_id)
                    ->lockForUpdate()
                    ->first();

                if ($schedule) {
                    $schedule->seats_available += $booking->total_adults + $booking->total_children;
                    $schedule->save();
                }

                $booking->payment_status = 'refunded';
                $booking->payments()->update(['status' => 'refunded']);
            }

            if ($newStatus === 'confirmed' && $booking->payment_status === 'unpaid') {
                $booking->payment_status = 'pending';
            }

            if ($newStatus === 'completed') {
                if ($booking->payment_status !== 'paid') {
                    $booking->payment_status = 'paid';
                }

                $booking->payments()
                    ->where('status', 'pending')
                    ->update([
                        'status' => 'success',
                        'paid_at' => now(),
                    ]);
            }

            $booking->status = $newStatus;
            $booking->save();
        });

        $booking->refresh();
        $booking->loadMissing('user', 'tourSchedule.tour');

        if ($booking->user) {
            if ($newStatus === 'confirmed') {
                $this->notifications->notify($booking->user, new BookingConfirmedNotification($booking));
            } elseif ($newStatus === 'completed') {
                $this->notifications->notify($booking->user, new BookingCompletedNotification($booking));

                if (!$booking->review_notified_at) {
                    $this->notifications->notify($booking->user, new BookingReviewRequestNotification($booking));
                    $booking->review_notified_at = now();
                    $booking->save();
                }
            } elseif ($newStatus === 'cancelled') {
                $this->notifications->notify($booking->user, new BookingCancelledNotification($booking, true));
            }
        }

        return response()->json(['message' => 'Booking status updated successfully.']);
    }

    private function getAuthenticatedPartner(): ?object
    {
        $userId = Auth::id();
        if (!$userId) {
            return null;
        }

        $partner = DB::table('partners')
            ->where('user_id', $userId)
            ->first();

        if (!$partner || $partner->status !== 'approved') {
            return null;
        }

        return $partner;
    }
}
