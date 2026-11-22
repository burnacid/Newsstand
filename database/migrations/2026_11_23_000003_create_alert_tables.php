<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Where a user wants alerts delivered.
        Schema::create('alert_channels', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('type', 16); // email | ntfy | gotify | slack | teams | discord
            $table->string('name');
            $table->text('config'); // encrypted JSON (webhook URLs, tokens)
            $table->json('events')->nullable(); // watchlist | kev
            $table->boolean('enabled')->default(true);
            $table->timestamp('last_sent_at')->nullable();
            $table->text('last_error')->nullable();
            $table->timestamps();
        });

        // Every alert per channel, once (dedupe_key), with its delivery status.
        Schema::create('alerts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('alert_channel_id')->constrained()->cascadeOnDelete();
            $table->string('dedupe_key', 100);
            $table->json('payload');
            $table->string('status', 10)->default('pending'); // pending | sent | failed
            $table->text('error')->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->timestamps();

            $table->unique(['alert_channel_id', 'dedupe_key']);
            $table->index(['alert_channel_id', 'status']);
        });

        Schema::table('watchlist_items', function (Blueprint $table) {
            // off | any | cve | kev
            $table->string('alert_level', 8)->default('off');
        });
    }

    public function down(): void
    {
        Schema::table('watchlist_items', function (Blueprint $table) {
            $table->dropColumn('alert_level');
        });

        Schema::dropIfExists('alerts');
        Schema::dropIfExists('alert_channels');
    }
};
