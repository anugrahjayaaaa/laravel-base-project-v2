<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * How many times the requester has re-run one failed export by hand.
 *
 * ## Why the count lives on the row
 *
 * The cap is per export, not per hour: three clicks on THIS row, and a fourth is
 * refused. That only means something if the number of clicks already spent is
 * recorded somewhere, and the only place that knows which row a click referred to
 * is the row itself. A rate limiter cannot answer it — its bucket is keyed on
 * user and IP, is shared with Export and Download, and expires hourly, so "have
 * I had three retries on THIS export" is not a question it can answer.
 *
 * ## `retry_count` counts CLICKS, not job attempts
 *
 * `GenerateAuditExport::$tries = 3` already retries inside one click, so the
 * worst case for one row is the cap times three whole-table CSV builds. This
 * column counts the clicks; the cap itself is operator-editable and lives in
 * `system_settings.audit_export_max_manual_retries`, read through the one method
 * `AuditExport::maxRetries()` rather than repeated as a literal in the action,
 * the Blade and the tests.
 *
 * `default(0)` and NOT NULL: every row that already exists was requested once and
 * never retried, and a nullable counter would make "is there budget left" a
 * three-valued question on exactly the rows a retry is about to be offered for.
 */
return new class () extends Migration {
    public function up(): void
    {
        Schema::table('audit_exports', function (Blueprint $table): void {
            $table->unsignedTinyInteger('retry_count')->default(0)->after('status');
        });
    }

    public function down(): void
    {
        Schema::table('audit_exports', function (Blueprint $table): void {
            $table->dropColumn('retry_count');
        });
    }
};
