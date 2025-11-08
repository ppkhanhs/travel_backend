<?php

namespace App\Models;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class Promotion extends Model
{
    use HasFactory;

    protected $table = 'promotions';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'id',
        'code',
        'description',
        'partner_id',
        'tour_id',
        'type',
        'discount_type',
        'value',
        'max_usage',
        'valid_from',
        'valid_to',
        'is_active',
        'auto_apply',
        'auto_issue_on_cancel',
    ];

    protected $casts = [
        'valid_from' => 'date',
        'valid_to' => 'date',
        'is_active' => 'boolean',
        'auto_apply' => 'boolean',
        'auto_issue_on_cancel' => 'boolean',
        'value' => 'float',
    ];

    public $timestamps = false;

    protected static function booted(): void
    {
        static::creating(function (Promotion $promotion) {
            if (empty($promotion->id)) {
                $promotion->id = (string) Str::uuid();
            }
        });
    }

    public function scopeActive($query)
    {
        $today = Carbon::today()->toDateString();

        return $query->where('is_active', true)
            ->where(function ($q) use ($today) {
                $q->whereNull('valid_from')
                    ->orWhere('valid_from', '<=', $today);
            })
            ->where(function ($q) use ($today) {
                $q->whereNull('valid_to')
                    ->orWhere('valid_to', '>=', $today);
            });
    }

    public function bookings(): BelongsToMany
    {
        return $this->belongsToMany(Booking::class, 'booking_promotions')
            ->withPivot(['discount_amount', 'discount_type', 'applied_value']);
    }

    public function partner(): BelongsTo
    {
        return $this->belongsTo(Partner::class);
    }

    public function tours(): BelongsToMany
    {
        return $this->belongsToMany(Tour::class, 'promotion_tour');
    }

    public function assignments(): HasMany
    {
        return $this->hasMany(PromotionAssignment::class);
    }

    public function isCurrentlyActive(): bool
    {
        return $this->isActiveAt(Carbon::today());
    }

    public function remainingUses(): ?int
    {
        if (is_null($this->max_usage)) {
            return null;
        }

        $usage = DB::table('booking_promotions')
            ->join('bookings', 'booking_promotions.booking_id', '=', 'bookings.id')
            ->where('booking_promotions.promotion_id', $this->id)
            ->whereNotIn('bookings.status', ['cancelled'])
            ->count();

        return max(0, (int) $this->max_usage - $usage);
    }

    public function isActiveAt(Carbon $date): bool
    {
        if (!$this->is_active) {
            return false;
        }

        if ($this->valid_from && $date->lt(Carbon::parse($this->valid_from))) {
            return false;
        }

        if ($this->valid_to && $date->gt(Carbon::parse($this->valid_to))) {
            return false;
        }

        return true;
    }

    public function isVoucher(): bool
    {
        return $this->type === 'voucher';
    }

    public function isAutoDiscount(): bool
    {
        return $this->auto_apply === true && $this->type === 'auto';
    }
}
