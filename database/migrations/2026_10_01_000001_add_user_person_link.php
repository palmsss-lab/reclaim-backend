<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->unsignedBigInteger('person_id')->nullable()->unique('users_person_id_unique');
            $table->foreign('person_id')->references('person_id')->on('person')->restrictOnDelete()->restrictOnUpdate();
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->dropForeign(['person_id']);
            $table->dropUnique('users_person_id_unique');
            $table->dropColumn('person_id');
        });
    }
};