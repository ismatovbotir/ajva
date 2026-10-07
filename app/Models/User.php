<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use App\Enums\UserRole;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
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
        'password',
        'role',
        'can_see_profit',
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
        'password' => 'hashed',
        'role' => UserRole::class,
        'can_see_profit' => 'boolean',
    ];

    public function isAdmin(): bool
    {
        return $this->role === UserRole::Admin;
    }

    /** Admins always see profit/cost data; everyone else needs the can_see_profit flag. */
    public function canSeeProfit(): bool
    {
        return $this->isAdmin() || (bool) $this->can_see_profit;
    }

    /** Admins and operators use the whole panel except the settings pages. */
    public function canUsePanel(): bool
    {
        return in_array($this->role, [UserRole::Admin, UserRole::Operator], true);
    }

    /** Shops an operator may see (ignored for admin/monitor, see App\Support\ShopAccess). */
    public function shops(): BelongsToMany
    {
        return $this->belongsToMany(Shop::class);
    }
}
