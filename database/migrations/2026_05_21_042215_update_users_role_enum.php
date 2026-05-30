<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
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

        DB::statement("UPDATE users SET role = CASE 
            WHEN role_temp = 'developer' THEN 'developer_frontend'
            WHEN role_temp = 'customer' THEN 'customer'
            WHEN role_temp = 'admin' THEN 'admin'
            ELSE 'developer_frontend'
        END");

        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('role_temp');
        });
    }

    public function down(): void
    {

    }
};