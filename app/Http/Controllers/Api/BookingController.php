<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\BookingResource;
use App\Models\Booking;
use App\Models\BookingPassenger;
use App\Models\Payment;
use App\Models\Tour;
use App\Models\TourPackage;
use App\Models\TourSchedule;
use App\Services\SepayService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Validation\ValidationException;

class BookingController extends Controller
{
    private SepayService $sepay;

    public function __construct(SepayService $sepay)
    {
        $this->sepay = $sepay;
    }

    public function index(Request $request): AnonymousResourceCollection
    {
        $bookings = Booking::with([
                'tourSchedule.tour.partner',
                'package',
                'passengers',
                'payments',
                'review',
            ])
            ->where('user_id', $request->user()->id)
            ->when($request->filled('status'), fn ($query) => $query->where('status', $request->status))
            ->orderByDesc('booking_date')
            ->paginate($request->integer('per_page', 15));

        return BookingResource::collection($bookings);
    }

    public function show(Request $request, string $id): BookingResource
    {
        $booking = Booking::with([
                'tourSchedule.tour.partner',
                'package',
                'passengers',
                'payments',
                'review',
            ])
            ->where('user_id', $request->user()->id)
            ->findOrFail($id);

        return new BookingResource($booking);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'tour_id' => 'required|uuid',
            'schedule_id' => 'required|uuid',
            'package_id' => 'required|uuid',
            'adults' => 'required|integer|min:1',
            'children' => 'nullable|integer|min:0',
            'contact_name' => 'required|string|max:255',
            'contact_email' => 'required|email|max:255',
            'contact_phone' => 'required|string|max:50',
            'notes' => 'nullable|string',
            'payment_method' => 'required|in:offline,sepay',
            'passengers' => 'required|array|min:1',
            'passengers.*.type' => 'required|in:adult,child',
            'passengers.*.full_name' => 'required|string|max:255',
            'passengers.*.gender' => 'nullable|string|max:20',
            'passengers.*.date_of_birth' => 'nullable|date',
            'passengers.*.document_number' => 'nullable|string|max:100',
        ]);

        $data['children'] = $data['children'] ?? 0;
        $totalRequested = $data['adults'] + $data['children'];

        if ($data['payment_method'] === 'sepay' && !$this->sepay->isEnabled() && !$this->hasSepayQrConfig()) {
            throw ValidationException::withMessages([
                'payment_method' => ['Sepay has not been configured. Please choose offline payment or contact support.'],
            ]);
        }

        if ($totalRequested <= 0) {
            throw ValidationException::withMessages([
                'adults' => ['Passenger count must be greater than zero.'],
            ]);
        }

        if (count($data['passengers']) !== $totalRequested) {
            throw ValidationException::withMessages([
                'passengers' => ['Passenger list does not match the number of travellers.'],
            ]);
        }

        $paymentUrl = null;
        $paymentQrUrl = null;
        $paymentId = null;

        $booking = DB::transaction(function () use ($request, $data, $totalRequested, &$paymentUrl, &$paymentId) {
            $tour = Tour::where('id', $data['tour_id'])
                ->where('status', 'approved')
                ->firstOrFail();

            $package = TourPackage::where('id', $data['package_id'])
                ->where('tour_id', $tour->id)
                ->where('is_active', true)
                ->firstOrFail();

            $schedule = TourSchedule::where('id', $data['schedule_id'])
                ->where('tour_id', $tour->id)
                ->lockForUpdate()
                ->firstOrFail();

            if (Carbon::parse($schedule->start_date)->isPast()) {
                throw ValidationException::withMessages([
                    'schedule_id' => ['Selected schedule is no longer available.'],
                ]);
            }

            if ($schedule->seats_available < $totalRequested) {
                throw ValidationException::withMessages([
                    'schedule_id' => ['Not enough seats are available for this departure.'],
                ]);
            }

            $adultPrice = (float) $package->adult_price;
            $childPrice = (float) ($package->child_price ?? round($package->adult_price * 0.75, 2));
            $totalPrice = $adultPrice * $data['adults'] + $childPrice * $data['children'];

            $booking = Booking::create([
                'user_id' => $request->user()->id,
                'tour_schedule_id' => $schedule->id,
                'package_id' => $package->id,
                'status' => 'pending',
                'total_price' => $totalPrice,
                'payment_status' => $data['payment_method'] === 'offline' ? 'unpaid' : 'pending',
                'total_adults' => $data['adults'],
                'total_children' => $data['children'],
                'contact_name' => $data['contact_name'],
                'contact_email' => $data['contact_email'],
                'contact_phone' => $data['contact_phone'],
                'notes' => $data['notes'] ?? null,
            ]);

            foreach ($data['passengers'] as $passengerData) {
                BookingPassenger::create([
                    'booking_id' => $booking->id,
                    'type' => $passengerData['type'],
                    'full_name' => $passengerData['full_name'],
                    'gender' => $passengerData['gender'] ?? null,
                    'date_of_birth' => $passengerData['date_of_birth'] ?? null,
                    'document_number' => $passengerData['document_number'] ?? null,
                ]);
            }

            $schedule->seats_available -= $totalRequested;
            $schedule->save();

            $payment = Payment::create([
                'booking_id' => $booking->id,
                'method' => $data['payment_method'],
                'amount' => $totalPrice,
                'tax' => 0,
                'status' => 'pending',
            ]);

            $paymentId = $payment->id;

            if ($data['payment_method'] === 'sepay') {
                $booking->loadMissing('user');
                $notifyUrl = route('payments.sepay.webhook');

                if ($this->sepay->isEnabled()) {
                    $paymentUrl = $this->sepay->createPaymentLink($payment, $booking, $notifyUrl, config('sepay.return_url'));
                } elseif ($this->hasSepayQrConfig()) {
                    $paymentQrUrl = $this->buildSepayQrUrl($booking, $payment);
                    $paymentUrl = $paymentQrUrl;
                }
            }

            return $booking;
        });

        $booking->load([
            'tourSchedule.tour.partner',
            'package',
            'passengers',
            'payments',
            'review',
        ]);

        return response()->json([
            'message' => 'Booking created successfully. Await partner confirmation.',
            'booking' => new BookingResource($booking),
            'payment_url' => $paymentUrl,
            'payment_qr_url' => $paymentQrUrl,
            'payment_id' => $paymentId,
        ], 201);
    }

    public function cancel(Request $request, string $id): JsonResponse
    {
        $booking = Booking::where('user_id', $request->user()->id)->findOrFail($id);

        if (!in_array($booking->status, ['pending', 'confirmed'], true)) {
            return response()->json(['message' => 'This booking can no longer be cancelled.'], 422);
        }

        DB::transaction(function () use ($booking) {
            $schedule = TourSchedule::where('id', $booking->tour_schedule_id)
                ->lockForUpdate()
                ->first();

            $booking->status = 'cancelled';
            $booking->payment_status = 'refunded';
            $booking->save();

            if ($schedule) {
                $schedule->seats_available += $booking->total_adults + $booking->total_children;
                $schedule->save();
            }

            $booking->payments()->update([
                'status' => 'refunded',
            ]);
        });

        return response()->json(['message' => 'Booking cancelled successfully.']);
    }

    private function hasSepayQrConfig(): bool
    {
        return !empty(config('sepay.account')) && !empty(config('sepay.bank'));
    }

    private function buildSepayQrUrl(Booking $booking, Payment $payment): ?string
    {
        $account = config('sepay.account');
        $bank = config('sepay.bank');
        $baseUrl = rtrim(config('sepay.qr_url', 'https://qr.sepay.vn/img'), '/');

        if (!$account || !$bank) {
            return null;
        }

        $amount = (int) round($payment->amount ?? $booking->total_price ?? 0);
        $pattern = (string) config('sepay.pattern', 'BOOKING-');
        $description = sprintf('%s%s', $pattern, $booking->id);

        $query = http_build_query([
            'acc' => $account,
            'bank' => $bank,
            'amount' => $amount,
            'des' => $description,
            'template' => 'compact',
        ]);

        return sprintf('%s?%s', $baseUrl, $query);
    }
}
