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
