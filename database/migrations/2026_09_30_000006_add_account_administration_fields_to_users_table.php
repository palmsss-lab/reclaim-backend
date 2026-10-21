<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->boolean('is_account_admin')->default(false);
            $table->dateTime('deactivated_at', 6)->nullable();
            $table->index(['is_account_admin', 'deactivated_at'], 'users_active_admin_lookup_idx');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->dropIndex('users_active_admin_lookup_idx');
            $table->dropColumn(['is_account_admin', 'deactivated_at']);
        });
    }
};
