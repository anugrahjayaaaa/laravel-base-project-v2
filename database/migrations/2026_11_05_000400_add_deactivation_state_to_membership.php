<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Admin deactivation state (Phase 18 revision, owner decision 2026-11-05).
 *
 * `members.is_active` is the member's own switch, not their subscription's: a
 * profile is inactive because an admin took it down, independently of whether
 * the tier it held has lapsed. That is what the users screen already models and
 * what the Active/Inactive tabs have to agree with.
 *
 * `member_subscriptions.paused_at` is the clock freeze for a deactivation that
 * was not a sanction. The row keeps its `status` and its `expires_at`, and the
 * paused span is added back on reactivation, so the days bought but not used
 * survive. A sanctioned deactivation writes `status = cancelled` instead —
 * nothing to resume.
 */
return new class () extends Migration {
    public function up(): void
    {
        Schema::table('members', function (Blueprint $table): void {
            $table->boolean('is_active')->default(true);
        });

        Schema::table('member_subscriptions', function (Blueprint $table): void {
            $table->timestamp('paused_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('member_subscriptions', function (Blueprint $table): void {
            $table->dropColumn('paused_at');
        });

        Schema::table('members', function (Blueprint $table): void {
            $table->dropColumn('is_active');
        });
    }
};
