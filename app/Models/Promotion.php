<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class Promotion extends Model
{
    use HasFactory;

    protected $table = 'promotions';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'id',
        'code',
        'discount_type',
        'value',
        'max_usage',
        'valid_from',
        'valid_to',
        'is_active',
    ];

    protected $casts = [
        'valid_from' => 'date',
        'valid_to' => 'date',
        'is_active' => 'boolean',
    ];

    public $timestamps = false;

    protected static function booted(): void
    {
        static::creating(function (Promotion $promotion) {
            if (empty($promotion->id)) {
                $promotion->id = (string) Str::uuid();
            }
        });
    }
}
