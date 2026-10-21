<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('finder_report', function (Blueprint $table): void {
            $table->id('report_id');
            $table->unsignedBigInteger('user_id');
            $table->text('item_description');
            $table->string('found_place', 200);
            $table->dateTime('found_at', 6);
            $table->unsignedBigInteger('category_id')->nullable();
            $table->dateTime('submitted_at', 6);
            $table->string('status', 12)->default('PENDING');
            $table->unsignedBigInteger('reviewed_by')->nullable();
            $table->dateTime('reviewed_at', 6)->nullable();
            $table->unsignedBigInteger('received_item_id')->nullable()->unique('finder_report_received_item_unique');
            $table->timestamps(6);
            $table->index(['user_id', 'status', 'created_at', 'report_id'], 'finder_report_owner_status_idx');
            $table->index(['status', 'submitted_at', 'report_id'], 'finder_report_staff_search_idx');
            $table->foreign('user_id')->references('id')->on('users')->restrictOnDelete()->restrictOnUpdate();
            $table->foreign('category_id')->references('category_id')->on('category')->restrictOnDelete()->restrictOnUpdate();
            $table->foreign('reviewed_by')->references('staff_id')->on('staff')->restrictOnDelete()->restrictOnUpdate();
            $table->foreign('received_item_id')->references('item_id')->on('found_item')->restrictOnDelete()->restrictOnUpdate();
        });

        Schema::create('missing_item_report', function (Blueprint $table): void {
            $table->id('report_id');
            $table->unsignedBigInteger('user_id');
            $table->text('item_description');
            $table->unsignedBigInteger('category_id')->nullable();
            $table->dateTime('lost_at', 6);
            $table->string('last_known_location', 200);
            $table->string('status', 10)->default('OPEN');
            $table->unsignedBigInteger('reviewed_by')->nullable();
            $table->dateTime('reviewed_at', 6)->nullable();
            $table->timestamps(6);
            $table->index(['user_id', 'status', 'created_at', 'report_id'], 'missing_report_owner_status_idx');
            $table->index(['status', 'category_id', 'lost_at', 'report_id'], 'missing_report_staff_search_idx');
            $table->foreign('user_id')->references('id')->on('users')->restrictOnDelete()->restrictOnUpdate();
            $table->foreign('category_id')->references('category_id')->on('category')->restrictOnDelete()->restrictOnUpdate();
            $table->foreign('reviewed_by')->references('staff_id')->on('staff')->restrictOnDelete()->restrictOnUpdate();
        });

        Schema::create('public_item_listing', function (Blueprint $table): void {
            $table->unsignedBigInteger('item_id')->primary();
            $table->text('public_description');
            $table->unsignedBigInteger('published_by');
            $table->dateTime('published_at', 6);
            $table->dateTime('unpublished_at', 6)->nullable();
            $table->index(['unpublished_at', 'published_at', 'item_id'], 'public_item_active_idx');
            $table->foreign('item_id')->references('item_id')->on('found_item')->restrictOnDelete()->restrictOnUpdate();
            $table->foreign('published_by')->references('staff_id')->on('staff')->restrictOnDelete()->restrictOnUpdate();
        });

        DB::statement("ALTER TABLE `finder_report` ADD CONSTRAINT `finder_report_description_nonblank` CHECK (CHAR_LENGTH(TRIM(`item_description`)) > 0), ADD CONSTRAINT `finder_report_place_nonblank` CHECK (CHAR_LENGTH(TRIM(`found_place`)) > 0), ADD CONSTRAINT `finder_report_status_valid` CHECK (BINARY `status` IN ('PENDING', 'RECEIVED', 'WITHDRAWN', 'CLOSED')), ADD CONSTRAINT `finder_report_review_fields_match_status` CHECK ((BINARY `status` IN ('PENDING', 'WITHDRAWN') AND `reviewed_by` IS NULL AND `reviewed_at` IS NULL) OR (BINARY `status` IN ('RECEIVED', 'CLOSED') AND `reviewed_by` IS NOT NULL AND `reviewed_at` IS NOT NULL))");
        DB::statement("ALTER TABLE `missing_item_report` ADD CONSTRAINT `missing_report_description_nonblank` CHECK (CHAR_LENGTH(TRIM(`item_description`)) > 0), ADD CONSTRAINT `missing_report_place_nonblank` CHECK (CHAR_LENGTH(TRIM(`last_known_location`)) > 0), ADD CONSTRAINT `missing_report_status_valid` CHECK (BINARY `status` IN ('OPEN', 'RESOLVED', 'WITHDRAWN'))");
        DB::statement("ALTER TABLE `public_item_listing` ADD CONSTRAINT `public_listing_description_nonblank` CHECK (CHAR_LENGTH(TRIM(`public_description`)) > 0), ADD CONSTRAINT `public_listing_unpublish_after_publish` CHECK (`unpublished_at` IS NULL OR `unpublished_at` >= `published_at`)");
    }

    public function down(): void
    {
        Schema::dropIfExists('public_item_listing');
        Schema::dropIfExists('missing_item_report');
        Schema::dropIfExists('finder_report');
    }
};