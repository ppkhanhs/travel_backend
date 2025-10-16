<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

class TourPackage extends Model
{
    use HasFactory;

    protected $table = 'tour_packages';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'id',
        'tour_id',
        'name',
        'description',
        'adult_price',
        'child_price',
        'is_active',
    ];

    protected $casts = [
        'adult_price' => 'float',
        'child_price' => 'float',
        'is_active' => 'boolean',
    ];

    protected static function booted(): void
    {
        static::creating(function (TourPackage $package) {
            if (empty($package->id)) {
                $package->id = (string) Str::uuid();
            }
        });
    }

    public function tour(): BelongsTo
    {
        return $this->belongsTo(Tour::class);
    }
}
