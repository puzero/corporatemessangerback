<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class UserMatrixToken extends Model
{
    use HasFactory;

    protected $table = 'user_matrix_tokens';

    protected $fillable = [
        'user_id',
        'matrix_user_id',
        'access_token',
        'expires_at',
    ];

    protected $casts = [
        'expires_at' => 'datetime',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function isValid(): bool
    {
        return !$this->expires_at || $this->expires_at->isFuture();
    }
}