<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\BookingResource;
use App\Models\Booking;
use App\Models\BookingPassenger;
use App\Models\CancellationPolicy;
use App\Models\Payment;
use App\Models\Promotion;
use App\Models\Tour;
use App\Models\TourPackage;
use App\Models\TourSchedule;
use App\Services\SepayService;
use App\Services\AutoPromotionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Validation\ValidationException;

class BookingController extends Controller
{
    public function __construct(
        private SepayService $sepay,
        private AutoPromotionService $autoPromotions
    ) {
    }

    public function index(Request $request): AnonymousResourceCollection
    {
        $bookings = Booking::with([
                'tourSchedule.tour.partner',
                'tourSchedule.tour.cancellationPolicies',
                'package',
                'passengers',
                'payments',
                'review',
                'promotions',
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
                'tourSchedule.tour.cancellationPolicies',
                'package',
                'passengers',
                'payments',
                'review',
                'promotions',
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
            'promotion_code' => 'nullable|string|max:50',
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

        $promotion = $this->resolvePromotion($data['promotion_code'] ?? null);
        unset($data['promotion_code']);

        $paymentUrl = null;
        $paymentQrUrl = null;
        $paymentId = null;

        $booking = DB::transaction(function () use ($request, $data, $totalRequested, &$paymentUrl, &$paymentId, &$paymentQrUrl, $promotion) {
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

            $this->ensurePassengerRules($tour, $schedule, $data['passengers']);

            if ($schedule->seats_available < $totalRequested) {
                throw ValidationException::withMessages([
                    'schedule_id' => ['Not enough seats are available for this departure.'],
                ]);
            }

            $adultPrice = (float) $package->adult_price;
            $childPrice = (float) ($package->child_price ?? round($package->adult_price * 0.75, 2));
            $subTotal = $adultPrice * $data['adults'] + $childPrice * $data['children'];

            $autoPromotions = $this->autoPromotions
                ->getAutoPromotionsForTour($tour->id, $tour->partner_id, Carbon::parse($schedule->start_date))
                ->all();

            $promotionChain = array_merge($autoPromotions, $promotion ? [$promotion] : []);
            [$totalPrice, $promotionDetails] = $this->calculatePromotionDiscount($subTotal, $promotionChain);

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

            foreach ($promotionDetails as $applied) {
                /** @var Promotion $appliedPromotion */
                $appliedPromotion = $applied['promotion'];

                $booking->promotions()->attach($appliedPromotion->id, [
                    'discount_amount' => $applied['discount'],
                    'discount_type' => $appliedPromotion->discount_type,
                    'applied_value' => $appliedPromotion->value,
                ]);
            }

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
            'tourSchedule.tour.cancellationPolicies',
            'package',
            'passengers',
            'payments',
            'review',
            'promotions',
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
        $booking = Booking::with([
            'tourSchedule.tour.cancellationPolicies',
            'payments',
        ])->where('user_id', $request->user()->id)->findOrFail($id);

        if (!in_array($booking->status, ['pending', 'confirmed'], true)) {
            return response()->json(['message' => 'This booking can no longer be cancelled.'], 422);
        }

        $appliedPolicy = null;
        $totalRefund = 0.0;

        DB::transaction(function () use ($booking, &$appliedPolicy, &$totalRefund) {
            $schedule = TourSchedule::with(['tour.cancellationPolicies' => function ($query) {
                $query->orderByDesc('days_before');
            }])->where('id', $booking->tour_schedule_id)
                ->lockForUpdate()
                ->first();

            $booking->status = 'cancelled';

            if ($schedule) {
                $tour = $schedule->tour ?? $booking->tourSchedule?->tour;

                $hoursUntilDeparture = now()->diffInHours(Carbon::parse($schedule->start_date), false);

                if ($hoursUntilDeparture < 0) {
                    throw ValidationException::withMessages([
                        'schedule_id' => ['The tour has already started and cannot be cancelled.'],
                    ]);
                }

                $daysUntilDeparture = (int) ceil($hoursUntilDeparture / 24);

                $policies = $tour?->cancellationPolicies ?? collect();

                if ($policies->isEmpty()) {
                    $appliedPolicy = new CancellationPolicy([
                        'days_before' => 0,
                        'refund_rate' => 100,
                    ]);
                } else {
                    $appliedPolicy = $policies->first(function (CancellationPolicy $policy) use ($daysUntilDeparture) {
                        return $daysUntilDeparture >= $policy->days_before;
                    });
                }

                if (!$appliedPolicy) {
                    throw ValidationException::withMessages([
                        'booking' => ['This booking cannot be cancelled within the current timeframe.'],
                    ]);
                }

                $schedule->seats_available += $booking->total_adults + $booking->total_children;
                $schedule->save();
            }

            $refundRate = max(0, min(100, $appliedPolicy->refund_rate ?? 0));
            $ratio = $refundRate / 100;

            $booking->payment_status = $refundRate > 0 ? 'refunded' : 'unpaid';
            $booking->save();

            $booking->payments->each(function (Payment $payment) use ($ratio, &$totalRefund) {
                $baseAmount = $payment->total_amount ?? $payment->amount ?? 0;
                $refundAmount = round($baseAmount * $ratio, 2);
                $payment->status = 'refunded';
                $payment->refund_amount = $refundAmount;
                $payment->save();
                $totalRefund += $refundAmount;
            });
        });

        $booking->promotions()->detach();

        return response()->json([
            'message' => 'Booking cancelled successfully.',
            'refund' => [
                'rate' => $appliedPolicy?->refund_rate ?? 0,
                'amount' => $totalRefund,
                'policy_days_before' => $appliedPolicy?->days_before,
            ],
        ]);
    }

    private function resolvePromotion(?string $code): ?Promotion
    {
        $code = is_string($code) ? trim($code) : '';

        if ($code === '') {
            return null;
        }

        $normalized = function_exists('mb_strtolower') ? mb_strtolower($code) : strtolower($code);

        $promotion = Promotion::query()
            ->whereRaw('LOWER(code) = ?', [$normalized])
            ->first();

        if (!$promotion) {
            throw ValidationException::withMessages([
                'promotion_code' => ['Promotion code is invalid.'],
            ]);
        }

        if (!$promotion->isCurrentlyActive()) {
            throw ValidationException::withMessages([
                'promotion_code' => ['Promotion code is not active.'],
            ]);
        }

        $remaining = $promotion->remainingUses();
        if (!is_null($remaining) && $remaining <= 0) {
            throw ValidationException::withMessages([
                'promotion_code' => ['Promotion code has reached its usage limit.'],
            ]);
        }

        return $promotion;
    }

    private function calculatePromotionDiscount(float $amount, ?Promotion $promotion): array
    {
        if (!$promotion || $amount <= 0) {
            return [round($amount, 2), 0.0];
        }

        $discount = 0.0;
        $type = strtolower($promotion->discount_type ?? '');

        if (in_array($type, ['percent', 'percentage'], true)) {
            $discount = round($amount * ($promotion->value / 100), 2);
        } else {
            $discount = (float) $promotion->value;
        }

        $discount = max(0.0, min($amount, $discount));
        $total = round($amount - $discount, 2);

        return [$total, $discount];
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

    private function ensurePassengerRules(Tour $tour, TourSchedule $schedule, array $passengers): void
    {
        $childAgeLimit = max(0, (int) ($tour->child_age_limit ?? 0));
        $departureDate = Carbon::parse($schedule->start_date);
        $requiresDocument = (bool) ($tour->requires_passport || $tour->requires_visa);

        foreach ($passengers as $index => $passenger) {
            $position = $index + 1;

            if (($passenger['type'] ?? null) === 'child') {
                if (empty($passenger['date_of_birth'])) {
                    throw ValidationException::withMessages([
                        "passengers.$index.date_of_birth" => ["Passenger #$position requires a date of birth for child tickets."],
                    ]);
                }

                $ageAtDeparture = Carbon::parse($passenger['date_of_birth'])->diffInYears($departureDate);

                if ($childAgeLimit > 0 && $ageAtDeparture > $childAgeLimit) {
                    throw ValidationException::withMessages([
                        "passengers.$index.date_of_birth" => ["Passenger #$position exceeds the child age limit of {$childAgeLimit}."],
                    ]);
                }
            }

            if ($requiresDocument && empty($passenger['document_number'])) {
                throw ValidationException::withMessages([
                    "passengers.$index.document_number" => ["Passenger #$position must provide travel documents for this tour."],
                ]);
            }
        }
    }
}

