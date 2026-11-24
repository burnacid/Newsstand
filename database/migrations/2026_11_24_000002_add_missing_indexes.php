<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * PostgreSQL (and SQLite) don't index foreign keys on their own. These back
 * lookups on hot paths: due feeds and rule evaluation (subscriptions.feed_id),
 * story groups (entries.cluster_id), cascades when pruning (rule_entry.entry_id)
 * and per-user / per-team listings.
 */
return new class extends Migration
{
    /** @var array<string, list<list<string>>> */
    private array $indexes = [
        'subscriptions' => [['feed_id']],
        'entries' => [['cluster_id']],
        'rule_entry' => [['entry_id']],
        'rules' => [['user_id', 'position'], ['team_id', 'position']],
        'alert_channels' => [['user_id'], ['team_id']],
        'alerts' => [['created_at'], ['status', 'created_at']],
        'watchlist_items' => [['team_id']],
        'entry_notes' => [['user_id']],
        'dashboard_widgets' => [['dashboard_id']],
        'dashboards' => [['team_id']],
    ];

    public function up(): void
    {
        foreach ($this->indexes as $table => $indexes) {
            Schema::table($table, function (Blueprint $t) use ($indexes) {
                foreach ($indexes as $columns) {
                    $t->index($columns);
                }
            });
        }

        // Partial indexes for the background sweeps over not-yet-processed stories.
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('CREATE INDEX entries_signals_pending_index ON entries (published_at) WHERE signals_processed = false');
            DB::statement('CREATE INDEX entries_article_pending_index ON entries (published_at) WHERE article_scanned = false');
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('DROP INDEX IF EXISTS entries_signals_pending_index');
            DB::statement('DROP INDEX IF EXISTS entries_article_pending_index');
        }

        foreach ($this->indexes as $table => $indexes) {
            Schema::table($table, function (Blueprint $t) use ($indexes) {
                foreach ($indexes as $columns) {
                    $t->dropIndex($columns);
                }
            });
        }
    }
};
