<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * PostgreSQL full-text search: a weighted, generated tsvector over title (A),
 * author + summary (B) and the article text with HTML stripped (C), plus a GIN
 * index. Uses the 'simple' configuration (no stemming) because feeds mix
 * languages. Other databases fall back to LIKE matching (see EntryQuery).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        DB::statement(<<<'SQL'
            ALTER TABLE entries ADD COLUMN search_vector tsvector GENERATED ALWAYS AS (
                setweight(to_tsvector('simple', coalesce(title, '')), 'A') ||
                setweight(to_tsvector('simple', coalesce(author, '')), 'B') ||
                setweight(to_tsvector('simple', coalesce(summary, '')), 'B') ||
                setweight(to_tsvector('simple', left(regexp_replace(coalesce(content, ''), '<[^>]+>', ' ', 'g'), 100000)), 'C')
            ) STORED
            SQL);

        DB::statement('CREATE INDEX entries_search_vector_index ON entries USING GIN (search_vector)');
    }

    public function down(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        DB::statement('DROP INDEX IF EXISTS entries_search_vector_index');
        DB::statement('ALTER TABLE entries DROP COLUMN IF EXISTS search_vector');
    }
};
