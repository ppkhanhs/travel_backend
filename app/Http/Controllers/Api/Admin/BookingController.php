<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\Booking;
use App\Models\TourSchedule;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class BookingController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $bookings = Booking::with(['user', 'tourSchedule.tour', 'package'])
            ->when($request->filled('status'), static fn ($query) => $query->where('status', $request->status))
            ->orderByDesc('booking_date')
            ->paginate($request->integer('per_page', 30));

        return response()->json($bookings);
    }

    public function show(string $id): JsonResponse
    {
        $booking = Booking::with(['user', 'tourSchedule.tour', 'package', 'passengers', 'payments'])
            ->findOrFail($id);

        return response()->json($booking);
    }

    public function updateStatus(Request $request, string $id): JsonResponse
    {
        $data = $request->validate([
            'status' => 'required|in:pending,confirmed,cancelled,completed',
        ]);

        $booking = Booking::findOrFail($id);
        $newStatus = $data['status'];

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

            if ($newStatus === 'completed' && $booking->payment_status !== 'paid') {
                if ($booking->payments()->where('status', 'success')->exists()) {
                    $booking->payment_status = 'paid';
                }
            }

            $booking->status = $newStatus;
            $booking->save();
        });

        return response()->json(['message' => 'Booking status updated successfully.']);
    }
}
