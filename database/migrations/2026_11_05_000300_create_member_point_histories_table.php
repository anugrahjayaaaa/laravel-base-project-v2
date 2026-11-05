<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The points ledger (Phase 18 P18-B1).
 *
 * Never edited: a correction is a new signed row. `balance_after` makes the
 * ledger auditable without recomputation, and `reference_*` is the morph that
 * the Phase 19 POS fills in when a sale earns points.
 */
return new class () extends Migration {
    public function up(): void
    {
        Schema::create('member_point_histories', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('member_id')->constrained('members')->cascadeOnDelete();
            $table->bigInteger('delta');
            $table->unsignedBigInteger('balance_after');
            $table->string('reason');
            $table->foreignId('actor_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->nullableMorphs('reference');
            $table->timestamps();

            $table->index(['member_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('member_point_histories');
    }
};
