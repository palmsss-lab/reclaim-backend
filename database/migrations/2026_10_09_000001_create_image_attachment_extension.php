<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('image_attachment', function (Blueprint $table): void {
            $table->bigIncrements('image_id');
            $table->unsignedBigInteger('finder_report_id')->nullable();
            $table->unsignedBigInteger('missing_report_id')->nullable();
            $table->unsignedBigInteger('item_id')->nullable();
            $table->string('path', 80)->unique();
            $table->unsignedInteger('width');
            $table->unsignedInteger('height');
            $table->unsignedInteger('bytes');
            $table->unsignedBigInteger('approved_by')->nullable();
            $table->dateTime('approved_at', 6)->nullable();
            $table->timestamps(6);
            $table->foreign('finder_report_id')->references('report_id')->on('finder_report')->restrictOnDelete();
            $table->foreign('missing_report_id')->references('report_id')->on('missing_item_report')->restrictOnDelete();
            $table->foreign('item_id')->references('item_id')->on('found_item')->restrictOnDelete();
            $table->foreign('approved_by')->references('staff_id')->on('staff')->restrictOnDelete();
        });
        DB::statement('ALTER TABLE image_attachment ADD CONSTRAINT image_one_parent CHECK ((finder_report_id IS NOT NULL) + (missing_report_id IS NOT NULL) + (item_id IS NOT NULL) = 1)');
        DB::statement('ALTER TABLE image_attachment ADD CONSTRAINT image_public_approval CHECK ((approved_by IS NULL AND approved_at IS NULL) OR (item_id IS NOT NULL AND approved_by IS NOT NULL AND approved_at IS NOT NULL))');
    }

    public function down(): void
    {
        Schema::dropIfExists('image_attachment');
    }
};
