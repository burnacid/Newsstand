<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->boolean('digest_enabled')->default(false);
            $table->unsignedTinyInteger('digest_hour')->default(7); // in the app timezone
            $table->date('digest_sent_on')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['digest_enabled', 'digest_hour', 'digest_sent_on']);
        });
    }
};
