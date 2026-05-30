<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('archived_messages', function (Blueprint $table) {
            $table->id();
            $table->string('room_id');                     
            $table->string('sender');                        
            $table->text('body')->nullable();             
            $table->string('msgtype')->default('m.text');     
            $table->string('event_id')->nullable()->index(); 
            $table->unsignedBigInteger('origin_server_ts')->nullable(); 
            $table->boolean('is_room_deleted')->default(false); 
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('archived_messages');
    }
};