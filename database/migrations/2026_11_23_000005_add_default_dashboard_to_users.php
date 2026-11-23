<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // The dashboard the sidebar "Dashboards" link opens; null = the dashboards overview.
        Schema::table('users', function (Blueprint $table) {
            $table->foreignId('default_dashboard_id')->nullable()->constrained('dashboards')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('users', fn (Blueprint $table) => $table->dropConstrainedForeignId('default_dashboard_id'));
    }
};
