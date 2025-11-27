<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use App\Models\Partner;
use App\Models\RecentTourView;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Laravel\Sanctum\HasApiTokens;

class User extends Authenticatable
{
    use HasApiTokens, HasFactory, Notifiable;

    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'name',
        'email',
        'phone',
        'password',
        'role',
        'status',
        'notifications_enabled',
        'preferences',
        'address_line1',
        'address_line2',
        'city',
        'state',
        'postal_code',
        'country',
    ];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var array<int, string>
     */
    protected $hidden = [
        'password',
        'remember_token',
    ];

    /**
     * The attributes that should be cast.
     *
     * @var array<string, string>
     */
    protected $casts = [
        'email_verified_at' => 'datetime',
        'phone_verified_at' => 'datetime',
        'notifications_enabled' => 'boolean',
        'preferences' => 'array',
    ];

    protected $keyType = 'string';      // id là string (UUID)
    public $incrementing = false;

    protected static function booted(): void
    {
        static::creating(function (User $user) {
            if (empty($user->id)) {
                $user->id = (string) Str::uuid();
            }

            if (Schema::hasColumn($user->getTable(), 'status') && empty($user->status)) {
                $user->status = 'active';
            }
        });
    }

    public function socialAccounts(): HasMany
    {
        return $this->hasMany(SocialAccount::class);
    }

    public function partner(): HasOne
    {
        return $this->hasOne(Partner::class);
    }

    public function analyticsEvents(): HasMany
    {
        return $this->hasMany(AnalyticsEvent::class);
    }

    public function recommendation(): HasOne
    {
        return $this->hasOne(UserRecommendation::class);
    }

    public function recentTourViews(): HasMany
    {
        return $this->hasMany(RecentTourView::class);
    }
}
