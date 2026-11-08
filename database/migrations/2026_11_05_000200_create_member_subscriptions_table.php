<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Subscription history per member (Phase 18 P18-B1).
 *
 * `role_name` is copied at activation, per row, rather than read through the
 * tier: a later tier edit must not make the sweep revoke the wrong role.
 * `(status, expires_at)` is the expiry sweep's only query shape.
 */
return new class () extends Migration {
    public function up(): void
    {
        Schema::create('member_subscriptions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('member_id')->constrained('members')->cascadeOnDelete();
            $table->foreignId('member_tier_id')->constrained('member_tiers')->restrictOnDelete();
            $table->enum('status', ['active', 'expired', 'cancelled'])->default('active');
            $table->timestamp('starts_at');
            $table->timestamp('expires_at');
            $table->string('role_name')->nullable();
            $table->timestamps();

            $table->index(['status', 'expires_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('member_subscriptions');
    }
};
