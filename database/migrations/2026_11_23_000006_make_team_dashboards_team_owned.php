<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 * Team dashboards belong to the team: they survive their creator leaving or being deleted
 * (owner_id becomes the nullable "created by"), and they are removed with the team.
 * Personal dashboards (team_id null) are still removed with their owner, by the User model.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('dashboards', function (Blueprint $table) {
            $table->dropForeign(['owner_id']);
            $table->dropForeign(['team_id']);
        });

        Schema::table('dashboards', function (Blueprint $table) {
            $table->foreignId('owner_id')->nullable()->change();
            $table->foreign('owner_id')->references('id')->on('users')->nullOnDelete();
            $table->foreign('team_id')->references('id')->on('teams')->cascadeOnDelete();
        });

        // Only team owners/admins edit team dashboards now. Creators who are plain members
        // keep editing the dashboards they made, through a direct "editor" share.
        $rows = DB::table('dashboards')
            ->join('team_user', fn ($join) => $join->on('team_user.team_id', '=', 'dashboards.team_id')
                ->on('team_user.user_id', '=', 'dashboards.owner_id'))
            ->where('team_user.role', 'member')
            ->whereNotExists(fn ($q) => $q->from('dashboard_user')
                ->whereColumn('dashboard_user.dashboard_id', 'dashboards.id')
                ->whereColumn('dashboard_user.user_id', 'dashboards.owner_id'))
            ->get(['dashboards.id', 'dashboards.owner_id']);

        foreach ($rows as $row) {
            DB::table('dashboard_user')->insert([
                'dashboard_id' => $row->id, 'user_id' => $row->owner_id, 'role' => 'editor',
                'created_at' => now(), 'updated_at' => now(),
            ]);
        }
    }

    public function down(): void
    {
        // Dashboards whose creator is gone can't go back to a required owner.
        DB::table('dashboards')->whereNull('owner_id')->delete();

        Schema::table('dashboards', function (Blueprint $table) {
            $table->dropForeign(['owner_id']);
            $table->dropForeign(['team_id']);
        });

        Schema::table('dashboards', function (Blueprint $table) {
            $table->foreignId('owner_id')->nullable(false)->change();
            $table->foreign('owner_id')->references('id')->on('users')->cascadeOnDelete();
            $table->foreign('team_id')->references('id')->on('teams')->nullOnDelete();
        });
    }
};
