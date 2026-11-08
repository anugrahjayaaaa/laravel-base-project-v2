<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The loyalty profile on an existing account (Phase 18 P18-B1, D-2).
 *
 * `user_id` is UNIQUE: one profile per account. A member is not a second
 * identity — roles, `can:` and the session guard keep resolving against
 * `App\Models\User`.
 */
return new class () extends Migration {
    public function up(): void
    {
        Schema::create('members', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->unique()->constrained()->restrictOnDelete();
            $table->string('member_number')->unique();
            $table->unsignedBigInteger('points_balance')->default(0);
            $table->timestamp('joined_at');
            $table->text('notes')->nullable();
            $table->softDeletes();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('members');
    }
};
