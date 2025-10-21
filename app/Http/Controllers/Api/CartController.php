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
            'items.schedule',
            'items.package',
        ]);

        return new CartResource($cart);
    }

    public function addItem(Request $request): CartResource
    {
        $data = $this->validateItemPayload($request);

        $cart = $this->getOrCreateCart($request->user()->id);

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

    public function removeItem(Request $request, string $id): JsonResponse
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
            'adults' => ['required', 'integer', 'min:0'],
            'children' => ['nullable', 'integer', 'min:0'],
        ];

        $data = $request->validate($rules);
        $data['children'] = $data['children'] ?? 0;

        if ($data['adults'] === 0 && $data['children'] === 0) {
            throw ValidationException::withMessages([
                'adults' => ['Số lượng vé phải lớn hơn 0.'],
            ]);
        }

        return $data;
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
                    'schedule_id' => ['Lịch khởi hành không thuộc tour được chọn.'],
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
                    'package_id' => ['Gói dịch vụ không thuộc tour được chọn.'],
                ]);
            }
        }

        return [$tour, $schedule, $package];
    }
}
