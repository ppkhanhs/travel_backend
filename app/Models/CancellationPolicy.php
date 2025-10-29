<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

class CancellationPolicy extends Model
{
    use HasFactory;

    protected $table = 'cancellation_policies';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'id',
        'tour_id',
        'days_before',
        'refund_rate',
        'description',
    ];

    protected $casts = [
        'days_before' => 'integer',
        'refund_rate' => 'float',
    ];

    protected static function booted(): void
    {
        static::creating(function (CancellationPolicy $policy) {
            if (empty($policy->id)) {
                $policy->id = (string) Str::uuid();
            }
        });
    }

    public function tour(): BelongsTo
    {
        return $this->belongsTo(Tour::class);
    }
}
