<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The two indexes the audit viewer reads through (Phase 10, P10-C2).
 *
 * Finding F2 in `docs/planning/phase-10-audit-trail.md`: the table had indexes
 * on `log_name` and on the two morph pairs, and the viewer sorts on
 * `created_at DESC` and filters on `event` on every single page load — neither
 * of which was indexed. `log_name` is one value for the whole table (F4), so
 * its index buys nothing and is left alone rather than dropped here: removing
 * an index the package's own config may rely on is not this migration's job.
 *
 * The composite serves `WHERE event = ? ORDER BY created_at DESC`; the
 * standalone serves the unfiltered default sort, where there is no `event` to
 * narrow on. Both are named — an unnamed index is a mystery on the next slow
 * query report.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::connection(config('activitylog.database_connection'))
            ->table(config('activitylog.table_name'), function (Blueprint $table) {
                $table->index(['event', 'created_at'], 'idx_activity_log_event_created_at');
                $table->index('created_at', 'idx_activity_log_created_at');
            });
    }

    public function down(): void
    {
        Schema::connection(config('activitylog.database_connection'))
            ->table(config('activitylog.table_name'), function (Blueprint $table) {
                $table->dropIndex('idx_activity_log_event_created_at');
                $table->dropIndex('idx_activity_log_created_at');
            });
    }
};
