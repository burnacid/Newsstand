<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 * Rules belong to a user OR a team. Team rules run on the team's shared feeds and are
 * managed by team owners and admins; they tag with team tags, highlight for every member
 * and alert the team's channels.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('rules', function (Blueprint $table) {
            $table->foreignId('user_id')->nullable()->change();
            $table->foreignId('team_id')->nullable()->after('user_id')->constrained()->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        DB::table('rules')->whereNull('user_id')->delete();

        Schema::table('rules', function (Blueprint $table) {
            $table->dropConstrainedForeignId('team_id');
        });

        Schema::table('rules', function (Blueprint $table) {
            $table->foreignId('user_id')->nullable(false)->change();
        });
    }
};
