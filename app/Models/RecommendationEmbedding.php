<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class RecommendationEmbedding extends Model
{
    use HasFactory;

    protected $table = 'recommendation_embeddings';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'id',
        'entity_type',
        'entity_id',
        'vector',
        'extra',
        'generated_at',
    ];

    protected $casts = [
        'vector' => 'array',
        'extra' => 'array',
        'generated_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        static::creating(function (RecommendationEmbedding $embedding) {
            if (empty($embedding->id)) {
                $embedding->id = (string) Str::uuid();
            }

            if (empty($embedding->generated_at)) {
                $embedding->generated_at = now();
            }
        });
    }
}
