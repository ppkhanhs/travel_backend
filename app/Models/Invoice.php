<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

class Invoice extends Model
{
    use HasFactory;

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'booking_id',
        'partner_id',
        'invoice_number',
        'status',
        'currency',
        'subtotal',
        'tax_amount',
        'total',
        'vat_rate',
        'customer_name',
        'customer_tax_code',
        'customer_address',
        'customer_email',
        'delivery_method',
        'emailed_at',
        'issued_at',
        'file_path',
        'line_items',
    ];

    protected $casts = [
        'issued_at' => 'datetime',
        'subtotal' => 'float',
        'tax_amount' => 'float',
        'total' => 'float',
        'vat_rate' => 'float',
        'line_items' => 'array',
        'emailed_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        static::creating(function (Invoice $invoice) {
            if (empty($invoice->id)) {
                $invoice->id = (string) Str::uuid();
            }

            if (empty($invoice->issued_at)) {
                $invoice->issued_at = now();
            }
        });
    }

    public function booking(): BelongsTo
    {
        return $this->belongsTo(Booking::class);
    }

    public function partner(): BelongsTo
    {
        return $this->belongsTo(Partner::class);
    }
}
