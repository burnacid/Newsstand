<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Manual ordering of feeds within a folder (drag and drop).
        Schema::table('subscriptions', function (Blueprint $table) {
            $table->unsignedInteger('position')->default(0)->after('custom_title');
        });

        // Personal labels on entries.
        Schema::create('tags', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('name', 50);
            $table->string('color', 20)->default('zinc');
            $table->timestamps();

            $table->unique(['user_id', 'name']);
        });

        Schema::create('entry_tag', function (Blueprint $table) {
            $table->foreignId('tag_id')->constrained()->cascadeOnDelete();
            $table->foreignId('entry_id')->constrained()->cascadeOnDelete();
            $table->timestamp('created_at')->nullable();

            $table->primary(['tag_id', 'entry_id']);
            $table->index('entry_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('entry_tag');
        Schema::dropIfExists('tags');

        Schema::table('subscriptions', function (Blueprint $table) {
            $table->dropColumn('position');
        });
    }
};
