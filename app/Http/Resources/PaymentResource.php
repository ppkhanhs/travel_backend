<?php

namespace App\Http\Resources;

use Illuminate\Http\Resources\Json\JsonResource;

class PaymentResource extends JsonResource
{
    public function toArray($request): array
    {
        return [
            'id' => $this->id,
            'method' => $this->method,
            'amount' => $this->amount,
            'discount_amount' => $this->discount_amount,
            'payable_amount' => max(0, ($this->amount ?? 0) - ($this->discount_amount ?? 0)),
            'tax' => $this->tax,
            'total_amount' => $this->total_amount,
            'refund_amount' => $this->refund_amount,
            'status' => $this->status,
            'transaction_code' => $this->transaction_code,
            'invoice_number' => $this->invoice_number,
            'paid_at' => optional($this->paid_at)->toIso8601String(),
        ];
    }
}
