<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // 1. Временно добавляем текстовое поле
        Schema::table('users', function (Blueprint $table) {
            $table->string('role_temp', 50)->nullable();
        });

        // 2. Копируем текущие значения, приводя к новой роли
        DB::statement("UPDATE users SET role_temp = 
            CASE 
                WHEN role IN ('developer_frontend', 'developer_backend') THEN 'developer'
                ELSE role
            END");

        // 3. Удаляем старое ENUM-поле
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('role');
        });

        // 4. Создаём новое ENUM с общей ролью developer
        Schema::table('users', function (Blueprint $table) {
            $table->enum('role', [
                'admin',
                'developer',    // единая роль для всех разработчиков
                'project_manager',
                'customer',
            ])->default('developer')->after('is_admin');
        });

        // 5. Переносим данные обратно
        DB::statement("UPDATE users SET role = role_temp");

        // 6. Удаляем временное поле
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('role_temp');
        });
    }

    public function down(): void
    {
        // Откат: возвращаем раздельные роли
        Schema::table('users', function (Blueprint $table) {
            $table->string('role_temp', 50)->nullable();
        });

        DB::statement("UPDATE users SET role_temp = role");

        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('role');
        });

        Schema::table('users', function (Blueprint $table) {
            $table->enum('role', [
                'developer_frontend',
                'developer_backend',
                'project_manager',
                'admin',
                'customer',
            ])->default('developer_frontend')->after('is_admin');
        });

        // При откате все developer станут developer_frontend (условно)
        DB::statement("UPDATE users SET role = 
            CASE 
                WHEN role_temp = 'developer' THEN 'developer_frontend'
                ELSE role_temp
            END");

        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('role_temp');
        });
    }
};