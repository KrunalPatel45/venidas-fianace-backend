<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

class User extends Authenticatable
{
    use HasApiTokens, HasFactory, Notifiable;

    protected $fillable = [
        'name',
        'name_gu',
        'email',
        'password',
        'member_code',
        'phone',
        'address',
        'role',
        'preferred_language',
        'is_active',
    ];

    protected $hidden = [
        'password',
        'remember_token',
    ];

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'is_active' => 'boolean',
        ];
    }

    public function isAdmin(): bool
    {
        return $this->role === 'admin';
    }

    public function isMember(): bool
    {
        return $this->role === 'member';
    }

    public function isExternal(): bool
    {
        return $this->role === 'external';
    }

    public function societyAccounts(): HasMany
    {
        return $this->hasMany(SocietyAccount::class);
    }

    public function receivedOpeningFees(): HasMany
    {
        return $this->hasMany(
            AccountOpeningFee::class,
            'received_by'
        );
    }

    public function getLocalizedName(?string $locale = null): string
    {
        $locale = $locale ?? app()->getLocale();

        if ($locale === 'gu' && !empty($this->name_gu)) {
            return $this->name_gu;
        }

        return $this->name;
    }
}