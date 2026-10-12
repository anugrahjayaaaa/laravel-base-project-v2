<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The hand-off row between the queued export job and the HTTP download.
 *
 * ## Why a row at all
 *
 * A job alone cannot serve a download link. The file lives on a disk the browser
 * cannot reach, and the browser needs a URL it can be redirected to — with an
 * ownership check attached to it. That check has to compare the requester to
 * something, and "something" has to be a row: a bare filename on disk carries no
 * record of who asked for it.
 *
 * ## The indexes are for the two queries that run without a user
 *
 * `user_id` is read on every download (the ownership check) and every expiry
 * sweep; `expires_at` is what the daily sweep filters on, and without it the
 * sweep is a full scan of a table that only ever grows. Both are small indexes on
 * columns that are already in the row, so the cost is the write side — which
 * happens once per export request, not once per exported row.
 */
return new class () extends Migration {
    public function up(): void
    {
        Schema::create('audit_exports', function (Blueprint $table): void {
            $table->id();

            // The requester. `restrictOnDelete` rather than cascade: an export is
            // evidence of what someone pulled out of the audit trail, so removing
            // the account must not silently remove the record that it was pulled.
            // The row is swept by `expires_at` like any other.
            $table->foreignId('user_id')->constrained()->restrictOnDelete();

            // The validated filters, verbatim. The export is "the list you were
            // looking at, as a file", so the job must be able to reproduce that
            // exact query later — which means storing what was asked for, not
            // rebuilding it from the job's constructor arguments.
            $table->json('filters');

            // An enum on the model, not a bare string; see AuditExportStatus.
            $table->string('status')->default('pending');

            // Null until the job writes the file. A `pending` row has no path,
            // and pretending otherwise would make "is this downloadable" a guess.
            $table->string('path')->nullable();

            $table->unsignedBigInteger('row_count')->nullable();

            // Set at request time, not at completion time: the download window is
            // the requester's seven days, and it must be answerable from the row
            // without knowing when the job happened to finish.
            $table->timestamp('expires_at');

            $table->timestamp('completed_at')->nullable();

            $table->timestamps();

            $table->index('expires_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('audit_exports');
    }
};
