<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

class User extends Authenticatable
{
    use HasApiTokens, HasFactory, Notifiable;

    protected $fillable = [
        'name',
        'email',
        'password',
        'avatar_url',
        'is_admin',
        'phone',
        'role',
        'is_blocked',
        'matrix_user_id',
        'position',
    ];

    protected $hidden = [
        'password',
        'remember_token',
    ];

    protected $casts = [
        'email_verified_at' => 'datetime',
        'is_admin' => 'boolean',
        'is_blocked' => 'boolean',
    ];

    // Отношение к матрикс-токену
    public function matrixToken()
    {
        return $this->hasOne(UserMatrixToken::class);
    }

    // Метод проверки администратора
    public function isAdmin(): bool
    {
        return $this->is_admin === true || $this->role === 'admin';
    }

    // Вспомогательные методы ролей
    public function isDeveloper(): bool
    {
        return $this->role === 'developer';
    }

    public function isCustomer(): bool
    {
        return $this->role === 'customer';
    }

    // Аксессор для роли (если не задана – developer)
    public function getRoleAttribute($value)
    {
        return $value ?? ($this->is_admin ? 'admin' : 'developer');
    }
}