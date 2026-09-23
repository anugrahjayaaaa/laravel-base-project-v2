<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->timestamp('username_changed_at')->nullable()->after('username');
            $table->timestamp('email_changed_at')->nullable()->after('username_changed_at');
            $table->string('pending_email')->nullable()->after('email');
            $table->string('email_change_token')->nullable()->after('pending_email');
            $table->timestamp('email_change_token_expires_at')->nullable()->after('email_change_token');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['username_changed_at', 'email_changed_at', 'pending_email', 'email_change_token', 'email_change_token_expires_at']);
        });
        Schema::dropIfExists('system_settings');
    }
};
