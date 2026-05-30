<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
public function up()
{
    Schema::table('archived_messages', function (Blueprint $table) {
        $table->string('media_url')->nullable()->after('body');
    });
}

public function down()
{
    Schema::table('archived_messages', function (Blueprint $table) {
        $table->dropColumn('media_url');
    });
}
};
