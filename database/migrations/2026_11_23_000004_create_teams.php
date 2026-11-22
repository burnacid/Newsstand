<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('teams', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('team_user', function (Blueprint $table) {
            $table->foreignId('team_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('role', 10)->default('member'); // owner | admin | member
            $table->timestamps();

            $table->primary(['team_id', 'user_id']);
            $table->index('user_id');
        });

        Schema::create('team_invitations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('team_id')->constrained()->cascadeOnDelete();
            $table->string('token', 64)->unique();
            $table->string('role', 10)->default('member');
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('expires_at');
            $table->timestamps();
        });

        // Folders, subscriptions, watchlist items and alert channels belong to a user OR a team.
        Schema::table('folders', function (Blueprint $table) {
            $table->foreignId('user_id')->nullable()->change();
            $table->foreignId('team_id')->nullable()->after('user_id')->constrained()->cascadeOnDelete();
            $table->unique(['team_id', 'name']);
        });

        Schema::table('subscriptions', function (Blueprint $table) {
            $table->foreignId('user_id')->nullable()->change();
            $table->foreignId('team_id')->nullable()->after('user_id')->constrained()->cascadeOnDelete();
            $table->unique(['team_id', 'feed_id']);
        });

        Schema::table('watchlist_items', function (Blueprint $table) {
            $table->foreignId('user_id')->nullable()->change();
            $table->foreignId('team_id')->nullable()->after('user_id')->constrained()->cascadeOnDelete();
        });

        Schema::table('alert_channels', function (Blueprint $table) {
            $table->foreignId('user_id')->nullable()->change();
            $table->foreignId('team_id')->nullable()->after('user_id')->constrained()->cascadeOnDelete();
        });

        // Members see a team dashboard (admins can edit it).
        Schema::table('dashboards', function (Blueprint $table) {
            $table->foreignId('team_id')->nullable()->after('owner_id')->constrained()->nullOnDelete();
        });

        Schema::create('entry_notes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('entry_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            // null = private note; otherwise visible to this team.
            $table->foreignId('team_id')->nullable()->constrained()->cascadeOnDelete();
            $table->text('body');
            $table->timestamps();

            $table->index(['entry_id', 'team_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('entry_notes');

        Schema::table('dashboards', fn (Blueprint $t) => $t->dropConstrainedForeignId('team_id'));
        Schema::table('alert_channels', fn (Blueprint $t) => $t->dropConstrainedForeignId('team_id'));
        Schema::table('watchlist_items', fn (Blueprint $t) => $t->dropConstrainedForeignId('team_id'));
        Schema::table('subscriptions', function (Blueprint $t) {
            $t->dropUnique(['team_id', 'feed_id']);
            $t->dropConstrainedForeignId('team_id');
        });
        Schema::table('folders', function (Blueprint $t) {
            $t->dropUnique(['team_id', 'name']);
            $t->dropConstrainedForeignId('team_id');
        });

        Schema::dropIfExists('team_invitations');
        Schema::dropIfExists('team_user');
        Schema::dropIfExists('teams');
    }
};
