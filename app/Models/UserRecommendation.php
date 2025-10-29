<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

class UserRecommendation extends Model
{
    use HasFactory;

    protected $table = 'user_recommendations';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'id',
        'user_id',
        'recommendations',
        'generated_at',
    ];

    protected $casts = [
        'recommendations' => 'array',
        'generated_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        static::creating(function (UserRecommendation $recommendation) {
            if (empty($recommendation->id)) {
                $recommendation->id = (string) Str::uuid();
            }

            if (empty($recommendation->generated_at)) {
                $recommendation->generated_at = now();
            }
        });
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
