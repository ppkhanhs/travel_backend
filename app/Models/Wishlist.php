<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

class Wishlist extends Model
{
    use HasFactory;

    protected $table = 'wishlists';

    public $incrementing = false;

    protected $keyType = 'string';

    public $timestamps = false;

    protected $fillable = [
        'id',
        'user_id',
        'tour_id',
        'created_at',
    ];

    protected static function booted(): void
    {
        static::creating(function (Wishlist $wishlist) {
            if (empty($wishlist->id)) {
                $wishlist->id = (string) Str::uuid();
            }

            if (empty($wishlist->created_at)) {
                $wishlist->created_at = now();
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

