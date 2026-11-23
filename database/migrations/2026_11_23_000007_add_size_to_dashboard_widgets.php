<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 * Widget width: normal (one column), wide (two columns) or full (the whole row).
 * Replaces the "wide" flag.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('dashboard_widgets', function (Blueprint $table) {
            $table->string('size', 10)->default('normal')->after('config');
        });

        DB::table('dashboard_widgets')->where('wide', true)->update(['size' => 'wide']);

        Schema::table('dashboard_widgets', function (Blueprint $table) {
            $table->dropColumn('wide');
        });
    }

    public function down(): void
    {
        Schema::table('dashboard_widgets', function (Blueprint $table) {
            $table->boolean('wide')->default(false)->after('config');
        });

        DB::table('dashboard_widgets')->whereIn('size', ['wide', 'full'])->update(['wide' => true]);

        Schema::table('dashboard_widgets', function (Blueprint $table) {
            $table->dropColumn('size');
        });
    }
};
