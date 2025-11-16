<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\CartResource;
use App\Models\Cart;
use App\Models\CartItem;
use App\Models\Tour;
use App\Models\TourPackage;
use App\Models\TourSchedule;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class CartController extends Controller
{
    public function show(Request $request): CartResource
    {
        $cart = $this->getOrCreateCart($request->user()->id);

        $cart->load([
            'items.tour',
            'items.tour.cancellationPolicies',
            'items.schedule',
            'items.package',
        ]);

        return new CartResource($cart);
    }

    public function addItem(Request $request): CartResource
    {
        $data = $this->validateItemPayload($request);

        $cart = $this->getOrCreateCart($request->user()->id);
        $tourId = $data['tour_id'];

        DB::transaction(function () use ($cart, $data) {
            [$tour, $schedule, $package] = $this->resolveTourReferences($data);

            $item = $cart->items()
                ->where('tour_id', $tour->id)
                ->where(function ($query) use ($data) {
                    $data['schedule_id']
                        ? $query->where('schedule_id', $data['schedule_id'])
                        : $query->whereNull('schedule_id');
                })
                ->where(function ($query) use ($data) {
                    $data['package_id']
                        ? $query->where('package_id', $data['package_id'])
                        : $query->whereNull('package_id');
                })
                ->lockForUpdate()
                ->first();

            if ($item) {
                $item->adult_quantity = $data['adults'];
                $item->child_quantity = $data['children'];
                $item->save();
            } else {
                $cart->items()->create([
                    'tour_id' => $tour->id,
                    'schedule_id' => $schedule?->id,
                    'package_id' => $package?->id,
                    'adult_quantity' => $data['adults'],
                    'child_quantity' => $data['children'],
                ]);
            }
        });

        $this->logUserActivity($request->user(), $tourId, 'cart_add');

        return $this->show($request);
    }

    public function updateItem(Request $request, string $id): CartResource
    {
        $data = $this->validateItemPayload($request, true);

        $cart = $this->getOrCreateCart($request->user()->id);

        DB::transaction(function () use ($cart, $id, $data) {
            /** @var CartItem $item */
            $item = $cart->items()->where('id', $id)->lockForUpdate()->firstOrFail();

            $item->adult_quantity = $data['adults'];
            $item->child_quantity = $data['children'];

            if ($item->adult_quantity === 0 && $item->child_quantity === 0) {
                $item->delete();
            } else {
                $item->save();
            }
        });

        return $this->show($request);
    }

    public function removeItem(Request $request, string $id): CartResource
    {
        $cart = $this->getOrCreateCart($request->user()->id);
        $cart->items()->where('id', $id)->delete();

        return $this->show($request)->additional([
            'message' => 'Item removed from cart.',
        ]);
    }

    private function getOrCreateCart(string $userId): Cart
    {
        return Cart::firstOrCreate(['user_id' => $userId]);
    }

    private function validateItemPayload(Request $request, bool $isUpdate = false): array
    {
        $rules = [
            'tour_id' => [$isUpdate ? 'sometimes' : 'required', 'uuid', 'exists:tours,id'],
            'schedule_id' => ['nullable', 'uuid', 'exists:tour_schedules,id'],
            'package_id' => ['nullable', 'uuid', 'exists:tour_packages,id'],
            'adults' => ['nullable', 'integer', 'min:0'],
            'children' => ['nullable', 'integer', 'min:0'],
            'adult_quantity' => ['nullable', 'integer', 'min:0'],
            'child_quantity' => ['nullable', 'integer', 'min:0'],
        ];

        $data = $request->validate($rules);

        $adults = $data['adults']
            ?? $data['adult_quantity']
            ?? $request->input('adultCount')
            ?? $request->input('adult_count')
            ?? ($isUpdate ? null : 0);

        $children = $data['children']
            ?? $data['child_quantity']
            ?? $request->input('childCount')
            ?? $request->input('child_count')
            ?? 0;

        if (!$isUpdate && is_null($adults)) {
            throw ValidationException::withMessages([
                'adults' => ['Vui lòng cung c?p s? lu?ng ngu?i l?n.'],
            ]);
        }

        $adults = max(0, (int) $adults);
        $children = max(0, (int) $children);

        if ($adults === 0 && $children === 0) {
            throw ValidationException::withMessages([
                'adults' => ['S? lu?ng vé ph?i l?n hon 0.'],
            ]);
        }

        return [
            'tour_id' => $data['tour_id'] ?? $request->input('tour_id'),
            'schedule_id' => $data['schedule_id'] ?? $request->input('schedule_id'),
            'package_id' => $data['package_id'] ?? $request->input('package_id'),
            'adults' => $adults,
            'children' => $children,
        ];
    }

    private function resolveTourReferences(array $data): array
    {
        /** @var Tour $tour */
        $tour = Tour::findOrFail($data['tour_id']);

        $schedule = null;
        if (!empty($data['schedule_id'])) {
            $schedule = TourSchedule::where('id', $data['schedule_id'])
                ->where('tour_id', $tour->id)
                ->first();

            if (!$schedule) {
                throw ValidationException::withMessages([
                    'schedule_id' => ['L?ch kh?i hành không thu?c tour du?c ch?n.'],
                ]);
            }
        }

        $package = null;
        if (!empty($data['package_id'])) {
            $package = TourPackage::where('id', $data['package_id'])
                ->where('tour_id', $tour->id)
                ->first();

            if (!$package) {
                throw ValidationException::withMessages([
                    'package_id' => ['Gói d?ch v? không thu?c tour du?c ch?n.'],
                ]);
            }
        }

        return [$tour, $schedule, $package];
    }
}

