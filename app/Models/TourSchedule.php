<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TourSchedule extends Model
{
    use HasFactory;

    protected $table = 'tour_schedules';

    protected $fillable = [
        'tour_id',
        'start_date',
        'end_date',
        'seats_total',
        'seats_available',
        'season_price',
    ];

    protected $casts = [
        'start_date' => 'date',
        'end_date' => 'date',
        'season_price' => 'decimal:2',
    ];

    public $timestamps = false;

    public function tour(): BelongsTo
    {
        return $this->belongsTo(Tour::class);
    }
}
