<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            // Composite index untuk counts() conditional aggregation
            // dan filter status di UserIndexAction::applyStatusFilter()
            $table->index(
                ['is_active', 'is_locked', 'deleted_at'],
                'idx_user_status_composite'
            );
            // Covering index untuk locked + trashed filter
            $table->index(
                ['is_locked', 'deleted_at'],
                'idx_user_locked_trashed'
            );
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropIndex('idx_user_status_composite');
            $table->dropIndex('idx_user_locked_trashed');
        });
    }
};
