<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

class CartItem extends Model
{
    use HasFactory;

    protected $table = 'cart_items';

    protected $fillable = [
        'id',
        'cart_id',
        'tour_id',
        'schedule_id',
        'package_id',
        'adult_quantity',
        'child_quantity',
    ];

    public $incrementing = false;

    protected $keyType = 'string';

    protected $casts = [
        'adult_quantity' => 'integer',
        'child_quantity' => 'integer',
    ];

    protected static function booted(): void
    {
        static::creating(function (CartItem $item) {
            if (empty($item->id)) {
                $item->id = (string) Str::uuid();
            }
        });
    }

    public function cart(): BelongsTo
    {
        return $this->belongsTo(Cart::class);
    }

    public function tour(): BelongsTo
    {
        return $this->belongsTo(Tour::class);
    }

    public function schedule(): BelongsTo
    {
        return $this->belongsTo(TourSchedule::class, 'schedule_id');
    }

    public function package(): BelongsTo
    {
        return $this->belongsTo(TourPackage::class, 'package_id');
    }
}

