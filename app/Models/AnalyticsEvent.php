<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

class AnalyticsEvent extends Model
{
    use HasFactory;

    protected $table = 'analytics_events';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'id',
        'user_id',
        'device_id',
        'session_id',
        'event_name',
        'entity_type',
        'entity_id',
        'metadata',
        'context',
        'occurred_at',
    ];

    protected $casts = [
        'metadata' => 'array',
        'context' => 'array',
        'occurred_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        static::creating(function (AnalyticsEvent $event) {
            if (empty($event->id)) {
                $event->id = (string) Str::uuid();
            }

            if (empty($event->occurred_at)) {
                $event->occurred_at = now();
            }
        });
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
