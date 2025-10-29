<?php

namespace App\Models;

use App\Models\Tour;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

class RecommendationPopularity extends Model
{
    use HasFactory;

    protected $table = 'recommendation_popularities';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'id',
        'tour_id',
        'bookings_count',
        'wishlist_count',
        'views_count',
        'score',
        'window',
    ];

    protected $casts = [
        'bookings_count' => 'integer',
        'wishlist_count' => 'integer',
        'views_count' => 'integer',
        'score' => 'float',
    ];

    protected static function booted(): void
    {
        static::creating(function (RecommendationPopularity $popularity) {
            if (empty($popularity->id)) {
                $popularity->id = (string) Str::uuid();
            }
        });
    }

    public function tour(): BelongsTo
    {
        return $this->belongsTo(Tour::class);
    }
}
