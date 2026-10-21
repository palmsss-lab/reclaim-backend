<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->unsignedBigInteger('staff_id')->nullable()->unique('users_staff_id_unique');
            $table->foreign('staff_id')->references('staff_id')->on('staff')->restrictOnDelete()->restrictOnUpdate();
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->dropForeign(['staff_id']);
            $table->dropUnique('users_staff_id_unique');
            $table->dropColumn('staff_id');
        });
    }
};