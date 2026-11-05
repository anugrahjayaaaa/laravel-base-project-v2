<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Membership tiers (Phase 18 P18-B1).
 *
 * `role_name` is a reference, not a foreign key: Spatie owns `roles` per guard
 * and a tier survives a guard reshuffle it has no opinion about. The Form
 * Request validates it against `RoleLookup::guard()` at write time.
 */
return new class () extends Migration {
    public function up(): void
    {
        Schema::create('member_tiers', function (Blueprint $table): void {
            $table->id();
            $table->string('name')->unique();
            $table->string('slug')->unique();
            $table->string('role_name')->nullable();
            $table->decimal('price', 12, 2)->default(0);
            $table->unsignedSmallInteger('duration_days');
            $table->decimal('point_multiplier', 4, 2)->default(1.00);
            $table->boolean('is_active')->default(true);
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->softDeletes();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('member_tiers');
    }
};
