<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('folders', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->unsignedInteger('position')->default(0);
            $table->timestamps();

            $table->unique(['user_id', 'name']);
        });

        Schema::create('subscriptions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('feed_id')->constrained()->cascadeOnDelete();
            $table->foreignId('folder_id')->nullable()->constrained()->nullOnDelete();
            $table->string('custom_title')->nullable();
            $table->timestamps();

            $table->unique(['user_id', 'feed_id']);
        });

        // Per-user state. No row means unread / not starred.
        Schema::create('entry_user', function (Blueprint $table) {
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('entry_id')->constrained()->cascadeOnDelete();
            $table->timestamp('read_at')->nullable();
            $table->timestamp('starred_at')->nullable();
            $table->timestamp('read_later_at')->nullable();
            $table->timestamps();

            $table->primary(['user_id', 'entry_id']);
            $table->index(['entry_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('entry_user');
        Schema::dropIfExists('subscriptions');
        Schema::dropIfExists('folders');
    }
};
