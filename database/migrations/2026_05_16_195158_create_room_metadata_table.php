// database/migrations/2026_05_16_000002_create_room_metadata_table.php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('room_metadata', function (Blueprint $table) {
            $table->id();
            $table->string('matrix_room_id')->unique();
            $table->foreignId('creator_id')->constrained('users')->onDelete('cascade');
            $table->enum('room_type', ['developer', 'customer', 'admin']);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('room_metadata');
    }
};
