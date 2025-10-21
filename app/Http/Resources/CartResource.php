<?php

namespace App\Http\Resources;

use Illuminate\Http\Resources\Json\JsonResource;

class CartResource extends JsonResource
{
    public function toArray($request): array
    {
        $items = CartItemResource::collection($this->whenLoaded('items'));

        $totals = $items->reduce(function ($carry, $item) {
            $pricing = $item['pricing'] ?? [];
            return [
                'adult_quantity' => $carry['adult_quantity'] + ($item['adult_quantity'] ?? 0),
                'child_quantity' => $carry['child_quantity'] + ($item['child_quantity'] ?? 0),
                'subtotal' => $carry['subtotal'] + ($pricing['subtotal'] ?? 0),
            ];
        }, [
            'adult_quantity' => 0,
            'child_quantity' => 0,
            'subtotal' => 0,
        ]);

        return [
            'id' => $this->id,
            'user_id' => $this->user_id,
            'totals' => $totals,
            'items' => $items,
        ];
    }
}

