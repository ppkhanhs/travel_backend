<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

class PromotionAssignment extends Model
{
    use HasFactory;

    protected $table = 'promotion_assignments';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'promotion_id',
        'user_id',
        'voucher_code',
        'status',
        'expires_at',
        'redeemed_at',
        'booking_id',
        'metadata',
    ];

    protected $casts = [
        'expires_at' => 'datetime',
        'redeemed_at' => 'datetime',
        'metadata' => 'array',
    ];

    protected static function booted(): void
    {
        static::creating(function (PromotionAssignment $assignment) {
            if (!$assignment->id) {
                $assignment->id = (string) Str::uuid();
            }
        });
    }

    public function promotion(): BelongsTo
    {
        return $this->belongsTo(Promotion::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function booking(): BelongsTo
    {
        return $this->belongsTo(Booking::class);
    }

    public function promotion(): BelongsTo
    {
        return $this->belongsTo(Promotion::class);
    }
}
