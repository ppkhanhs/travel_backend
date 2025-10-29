<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

class Payment extends Model
{
    use HasFactory;

    protected $table = 'payments';

    public $incrementing = false;

    protected $keyType = 'string';

    public $timestamps = false;

    protected $fillable = [
        'id',
        'booking_id',
        'method',
        'amount',
        'tax',
        'total_amount',
        'invoice_number',
        'transaction_code',
        'status',
        'paid_at',
        'refund_amount',
    ];

    protected $casts = [
        'amount' => 'float',
        'tax' => 'float',
        'total_amount' => 'float',
        'paid_at' => 'datetime',
        'refund_amount' => 'float',
    ];

    protected static function booted(): void
    {
        static::creating(function (Payment $payment) {
            if (empty($payment->id)) {
                $payment->id = (string) Str::uuid();
            }
        });
    }

    public function booking(): BelongsTo
    {
        return $this->belongsTo(Booking::class);
    }
}
