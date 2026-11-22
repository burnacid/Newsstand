<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('dashboards', function (Blueprint $table) {
            $table->id();
            $table->foreignId('owner_id')->constrained('users')->cascadeOnDelete();
            $table->string('name');
            $table->string('description', 500)->nullable();
            // Read-only public link; null when not shared publicly.
            $table->string('share_token', 64)->nullable()->unique();
            $table->timestamps();
        });

        Schema::create('dashboard_widgets', function (Blueprint $table) {
            $table->id();
            $table->foreignId('dashboard_id')->constrained()->cascadeOnDelete();
            $table->string('type', 20);
            $table->string('title')->nullable();
            $table->json('config');
            $table->boolean('wide')->default(false);
            $table->unsignedInteger('position')->default(0);
            $table->timestamps();
        });

        Schema::create('dashboard_user', function (Blueprint $table) {
            $table->foreignId('dashboard_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('role', 10)->default('viewer'); // viewer | editor
            $table->timestamps();

            $table->primary(['dashboard_id', 'user_id']);
            $table->index('user_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('dashboard_user');
        Schema::dropIfExists('dashboard_widgets');
        Schema::dropIfExists('dashboards');
    }
};
