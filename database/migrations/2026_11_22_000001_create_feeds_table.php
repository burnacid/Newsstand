<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('feeds', function (Blueprint $table) {
            $table->id();
            $table->text('url');
            $table->char('url_hash', 40)->unique();
            $table->text('site_url')->nullable();
            $table->string('title')->nullable();
            $table->text('description')->nullable();
            $table->string('etag')->nullable();
            $table->string('last_modified')->nullable();
            $table->boolean('full_text')->default(false);
            $table->unsignedInteger('fetch_interval')->default(60);
            $table->timestamp('last_fetched_at')->nullable();
            $table->timestamp('last_success_at')->nullable();
            $table->timestamp('next_fetch_at')->nullable()->index();
            $table->unsignedInteger('error_count')->default(0);
            $table->text('last_error')->nullable();
            $table->timestamps();
        });

        Schema::create('entries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('feed_id')->constrained()->cascadeOnDelete();
            $table->char('guid_hash', 40);
            $table->text('guid');
            $table->text('url')->nullable();
            $table->char('url_hash', 40)->nullable()->index();
            $table->text('title')->nullable();
            $table->string('author')->nullable();
            $table->text('summary')->nullable();
            $table->longText('content')->nullable();
            $table->boolean('full_text_extracted')->default(false);
            $table->text('image_url')->nullable();
            $table->timestamp('published_at')->index();
            $table->timestamps();

            $table->unique(['feed_id', 'guid_hash']);
            $table->index(['feed_id', 'published_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('entries');
        Schema::dropIfExists('feeds');
    }
};
