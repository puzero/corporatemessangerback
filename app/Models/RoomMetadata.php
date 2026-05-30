<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class RoomMetadata extends Model
{
    use HasFactory;

    protected $table = 'room_metadata';
    protected $fillable = ['matrix_room_id', 'creator_id', 'room_type'];

    public function creator()
    {
        return $this->belongsTo(User::class, 'creator_id');
    }
}