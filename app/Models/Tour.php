<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Tour extends Model
{
    use HasFactory;

    protected $table = 'tours';

    protected $fillable = [
        'partner_id',
        'title',
        'description',
        'destination',
        'duration',
        'base_price',
        'policy',
        'tags',
        'media',
        'itinerary',
        'status',
    ];

    protected $casts = [
        'base_price' => 'float',
        'tags' => 'array',
        'media' => 'array',
        'itinerary' => 'array',
    ];

    public $incrementing = false;

    protected $keyType = 'string';

    public $timestamps = false;

    public function partner(): BelongsTo
    {
        return $this->belongsTo(Partner::class);
    }

    public function categories(): BelongsToMany
    {
        return $this->belongsToMany(Category::class, 'tour_categories');
    }

    public function schedules(): HasMany
    {
        return $this->hasMany(TourSchedule::class, 'tour_id');
    }

    public function scopeApproved($query)
    {
        return $query->where('status', 'approved');
    }
}
