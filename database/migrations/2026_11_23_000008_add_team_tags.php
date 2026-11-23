<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 * Tags belong to a user OR a team. Team tags are shared labels: any member can put them on
 * stories, owners and admins manage them, and team dashboards can show them.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tags', function (Blueprint $table) {
            $table->foreignId('user_id')->nullable()->change();
            $table->foreignId('team_id')->nullable()->after('user_id')->constrained()->cascadeOnDelete();
            $table->unique(['team_id', 'name']);
        });
    }

    public function down(): void
    {
        DB::table('tags')->whereNull('user_id')->delete();

        Schema::table('tags', function (Blueprint $table) {
            $table->dropUnique(['team_id', 'name']);
            $table->dropConstrainedForeignId('team_id');
        });

        Schema::table('tags', function (Blueprint $table) {
            $table->foreignId('user_id')->nullable(false)->change();
        });
    }
};
