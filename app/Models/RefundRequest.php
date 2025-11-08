<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

class RefundRequest extends Model
{
    use HasFactory;

    protected $table = 'refund_requests';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'booking_id',
        'user_id',
        'partner_id',
        'status',
        'amount',
        'currency',
        'bank_account_name',
        'bank_account_number',
        'bank_name',
        'bank_branch',
        'customer_message',
        'partner_message',
        'proof_url',
        'partner_marked_at',
        'customer_confirmed_at',
    ];

    protected $casts = [
        'amount' => 'float',
        'partner_marked_at' => 'datetime',
        'customer_confirmed_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        static::creating(function (RefundRequest $request) {
            if (empty($request->id)) {
                $request->id = (string) Str::uuid();
            }
        });
    }

    public function booking(): BelongsTo
    {
        return $this->belongsTo(Booking::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function partner(): BelongsTo
    {
        return $this->belongsTo(Partner::class);
    }
}

