<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('claim_proof_image', function (Blueprint $table): void {
            $table->unsignedBigInteger('item_id');
            $table->unsignedBigInteger('claimant_id');
            $table->primary(['item_id', 'claimant_id']);
            $table->foreign(['item_id', 'claimant_id'])->references(['item_id', 'claimant_id'])->on('claim')->restrictOnDelete()->restrictOnUpdate();
            $table->string('path', 80)->unique();
            $table->unsignedInteger('width');
            $table->unsignedInteger('height');
            $table->unsignedInteger('bytes');
            $table->dateTime('created_at', 6);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('claim_proof_image');
    }
};
