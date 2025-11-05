<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

class RecentTourView extends Model
{
    use HasFactory;

    protected $table = 'recent_tour_views';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'user_id',
        'tour_id',
        'viewed_at',
        'view_count',
    ];

    protected $casts = [
        'viewed_at' => 'datetime',
        'view_count' => 'integer',
    ];

    protected static function booted(): void
    {
        static::creating(function (RecentTourView $view) {
            if (empty($view->id)) {
                $view->id = (string) Str::uuid();
            }

            if (is_null($view->viewed_at)) {
                $view->viewed_at = now();
            }

            if (is_null($view->view_count)) {
                $view->view_count = 1;
            }
        });
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function tour(): BelongsTo
    {
        return $this->belongsTo(Tour::class);
    }
}

