<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

class Partner extends Model
{
    use HasFactory;

    protected $table = 'partners';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'id',
        'user_id',
        'company_name',
        'tax_code',
        'address',
        'status',
    ];

    public $timestamps = false;

    protected static function booted(): void
    {
        static::creating(function (Partner $partner) {
            if (empty($partner->id)) {
                $partner->id = (string) Str::uuid();
            }
        });
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function tours(): HasMany
    {
        return $this->hasMany(Tour::class);
    }
}
