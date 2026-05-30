<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ArchivedMessage extends Model
{
    use HasFactory;

protected $fillable = [
    'room_id', 'room_name', 'sender', 'body', 'media_url', 'msgtype',
    'event_id', 'origin_server_ts', 'is_room_deleted',
];
}