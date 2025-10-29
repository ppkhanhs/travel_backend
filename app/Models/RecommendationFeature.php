<?php

namespace App\Models;

use App\Models\Tour;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

class RecommendationFeature extends Model
{
    use HasFactory;

    protected $table = 'recommendation_features';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'id',
        'tour_id',
        'features',
        'calculated_at',
    ];

    protected $casts = [
        'features' => 'array',
        'calculated_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        static::creating(function (RecommendationFeature $feature) {
            if (empty($feature->id)) {
                $feature->id = (string) Str::uuid();
            }

            if (empty($feature->calculated_at)) {
                $feature->calculated_at = now();
            }
        });
    }

    public function tour(): BelongsTo
    {
        return $this->belongsTo(Tour::class);
    }
}
