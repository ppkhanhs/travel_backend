<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class UserActivityLog extends Model
{
    use HasFactory;

    protected $table = 'user_activity_logs';

    public $incrementing = false;

    protected $keyType = 'string';

    public $timestamps = false;

    protected $fillable = [
        'id',
        'user_id',
        'tour_id',
        'action',
        'created_at',
    ];

    protected static function booted(): void
    {
        static::creating(function (UserActivityLog $log) {
            if (empty($log->id)) {
                $log->id = (string) Str::uuid();
            }

            if (empty($log->created_at)) {
                $log->created_at = now();
            }
        });
    }
}

