<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

class SocialAccount extends Model
{
    use HasFactory;

    protected $table = 'social_accounts';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'id',
        'user_id',
        'provider',
        'provider_user_id',
        'email',
        'access_token',
        'refresh_token',
        'token_expires_at',
        'meta',
    ];

    protected $casts = [
        'token_expires_at' => 'datetime',
        'meta' => 'array',
    ];

    protected static function booted(): void
    {
        static::creating(function (self $account) {
            if (empty($account->id)) {
                $account->id = (string) Str::uuid();
            }
        });
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
