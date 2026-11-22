<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Vulnerabilities mentioned in stories, enriched from NVD (CVSS) and CISA KEV.
        Schema::create('cves', function (Blueprint $table) {
            $table->id();
            $table->string('cve_id', 32)->unique();
            $table->decimal('cvss_score', 3, 1)->nullable()->index();
            $table->string('severity', 16)->nullable();
            $table->string('cvss_vector')->nullable();
            $table->text('description')->nullable();
            $table->timestamp('published_at')->nullable();
            $table->timestamp('nvd_checked_at')->nullable();

            // CISA Known Exploited Vulnerabilities
            $table->boolean('in_kev')->default(false)->index();
            $table->string('kev_vendor')->nullable();
            $table->string('kev_product')->nullable();
            $table->string('kev_name')->nullable();
            $table->date('kev_added_on')->nullable();
            $table->date('kev_due_on')->nullable();
            $table->boolean('kev_ransomware')->default(false);
            $table->timestamps();
        });

        Schema::create('cve_entry', function (Blueprint $table) {
            $table->foreignId('cve_id')->constrained()->cascadeOnDelete();
            $table->foreignId('entry_id')->constrained()->cascadeOnDelete();

            $table->primary(['cve_id', 'entry_id']);
            $table->index('entry_id');
        });

        // The same story covered by several feeds.
        Schema::create('story_clusters', function (Blueprint $table) {
            $table->id();
            $table->unsignedInteger('size')->default(1);
            $table->timestamp('last_seen_at')->index();
            $table->timestamps();
        });

        Schema::table('entries', function (Blueprint $table) {
            $table->foreignId('cluster_id')->nullable()->after('feed_id')->constrained('story_clusters')->nullOnDelete();
            $table->boolean('signals_processed')->default(false)->after('full_text_extracted');
        });

        // Per-user rule effects on entries.
        Schema::table('entry_user', function (Blueprint $table) {
            $table->string('highlight', 20)->nullable()->after('read_later_at');
            $table->timestamp('muted_at')->nullable()->after('highlight');
        });

        Schema::create('rules', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->boolean('enabled')->default(true);
            $table->string('match', 3)->default('all'); // all | any
            $table->json('conditions');
            $table->json('actions');
            $table->unsignedInteger('position')->default(0);
            $table->unsignedInteger('matches_count')->default(0);
            $table->timestamps();
        });

        // Which rules already ran on which entries, so actions apply once.
        Schema::create('rule_entry', function (Blueprint $table) {
            $table->foreignId('rule_id')->constrained()->cascadeOnDelete();
            $table->foreignId('entry_id')->constrained()->cascadeOnDelete();
            $table->timestamp('created_at')->nullable();

            $table->primary(['rule_id', 'entry_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('rule_entry');
        Schema::dropIfExists('rules');

        Schema::table('entry_user', function (Blueprint $table) {
            $table->dropColumn(['highlight', 'muted_at']);
        });

        Schema::table('entries', function (Blueprint $table) {
            $table->dropConstrainedForeignId('cluster_id');
            $table->dropColumn('signals_processed');
        });

        Schema::dropIfExists('story_clusters');
        Schema::dropIfExists('cve_entry');
        Schema::dropIfExists('cves');
    }
};
