<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Full-text extraction becomes each subscriber's choice; feeds.full_text now
        // means "at least one subscriber wants it" and is kept in sync by the app.
        Schema::table('subscriptions', function (Blueprint $table) {
            $table->boolean('full_text')->default(false)->after('custom_title');
        });

        // Everyone subscribed to a feed that had it on keeps it on.
        DB::table('subscriptions')
            ->whereIn('feed_id', DB::table('feeds')->select('id')->where('full_text', true))
            ->update(['full_text' => true]);
    }

    public function down(): void
    {
        Schema::table('subscriptions', function (Blueprint $table) {
            $table->dropColumn('full_text');
        });
    }
};
