<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('entries', function (Blueprint $table) {
            // The linked article was fetched once to look for CVEs the feed teaser didn't mention.
            $table->boolean('article_scanned')->default(false)->after('signals_processed');
        });
    }

    public function down(): void
    {
        Schema::table('entries', function (Blueprint $table) {
            $table->dropColumn('article_scanned');
        });
    }
};
