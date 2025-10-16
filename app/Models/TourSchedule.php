<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class TourSchedule extends Model
{
    use HasFactory;

    protected $table = 'tour_schedules';

    public $incrementing = false;

    protected $keyType = 'string';

    public $timestamps = false;

    protected $fillable = [
        'id',
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
        'seats_total' => 'integer',
        'seats_available' => 'integer',
        'season_price' => 'float',
    ];

    protected static function booted(): void
    {
        static::creating(function (TourSchedule $schedule) {
            if (empty($schedule->id)) {
                $schedule->id = (string) \Illuminate\Support\Str::uuid();
            }

            if (is_null($schedule->seats_available)) {
                $schedule->seats_available = $schedule->seats_total;
            }
        });
    }

    public function tour(): BelongsTo
    {
        return $this->belongsTo(Tour::class);
    }

    public function bookings(): HasMany
    {
        return $this->hasMany(Booking::class, 'tour_schedule_id');
    }
}
