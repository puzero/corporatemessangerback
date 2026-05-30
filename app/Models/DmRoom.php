<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class DmRoom extends Model
{
    use HasFactory;

    protected $table = 'dm_rooms';

    /**
     * Поля, разрешённые для массового заполнения.
     *
     * @var array
     */
    protected $fillable = [
        'user1_id',
        'user2_id',
        'matrix_room_id',
    ];

    /**
     * Отношение к первому пользователю.
     */
    public function user1()
    {
        return $this->belongsTo(User::class, 'user1_id');
    }

    /**
     * Отношение ко второму пользователю.
     */
    public function user2()
    {
        return $this->belongsTo(User::class, 'user2_id');
    }
}