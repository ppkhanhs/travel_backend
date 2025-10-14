<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

class Category extends Model
{
    use HasFactory;

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'id',
        'name',
        'slug',
        'parent_id',
    ];

    public $timestamps = false;

    protected static function booted(): void
    {
        static::creating(function (Category $category) {
            if (empty($category->id)) {
                $category->id = (string) Str::uuid();
            }
        });
    }

    public function tours(): BelongsToMany
    {
        return $this->belongsToMany(Tour::class, 'tour_categories');
    }

    public function parent(): BelongsTo
    {
        return $this->belongsTo(Category::class, 'parent_id');
    }

    public function children(): HasMany
    {
        return $this->hasMany(Category::class, 'parent_id');
    }
}
