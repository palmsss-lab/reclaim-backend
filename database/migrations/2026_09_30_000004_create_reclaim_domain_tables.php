<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('person', function (Blueprint $table): void {
            $table->id('person_id');
            $table->string('full_name', 120);
            $table->string('contact_details', 200);
        });

        Schema::create('staff', function (Blueprint $table): void {
            $table->id('staff_id');
            $table->string('full_name', 120);
        });

        Schema::create('category', function (Blueprint $table): void {
            $table->id('category_id');
            $table->string('category_name', 60)->unique();
        });

        Schema::create('storage_location', function (Blueprint $table): void {
            $table->id('location_id');
            $table->string('location_code', 30)->unique();
            $table->string('location_description', 200);
        });

        Schema::create('found_item', function (Blueprint $table): void {
            $table->id('item_id');
            $table->unsignedBigInteger('finder_id');
            $table->unsignedBigInteger('received_by');
            $table->unsignedBigInteger('category_id');
            $table->text('item_description');
            $table->string('found_place', 200);
            $table->dateTime('found_at', 6);
            $table->dateTime('received_at', 6);
            $table->foreign('finder_id')->references('person_id')->on('person')->restrictOnDelete()->restrictOnUpdate();
            $table->foreign('received_by')->references('staff_id')->on('staff')->restrictOnDelete()->restrictOnUpdate();
            $table->foreign('category_id')->references('category_id')->on('category')->restrictOnDelete()->restrictOnUpdate();
        });

        Schema::create('storage_assignment', function (Blueprint $table): void {
            $table->unsignedBigInteger('item_id');
            $table->unsignedInteger('assignment_no');
            $table->unsignedBigInteger('location_id');
            $table->unsignedBigInteger('assigned_by');
            $table->dateTime('assigned_at', 6);
            $table->dateTime('removed_at', 6)->nullable();
            $table->primary(['item_id', 'assignment_no']);
            $table->unique(['item_id', 'assigned_at'], 'storage_assignment_item_assigned_at_unique');
            $table->foreign('item_id')->references('item_id')->on('found_item')->restrictOnDelete()->restrictOnUpdate();
            $table->foreign('location_id')->references('location_id')->on('storage_location')->restrictOnDelete()->restrictOnUpdate();
            $table->foreign('assigned_by')->references('staff_id')->on('staff')->restrictOnDelete()->restrictOnUpdate();
        });

        Schema::create('claim', function (Blueprint $table): void {
            $table->unsignedBigInteger('item_id');
            $table->unsignedBigInteger('claimant_id');
            $table->dateTime('submitted_at', 6);
            $table->text('evidence_text');
            $table->string('outcome', 10);
            $table->unsignedBigInteger('reviewed_by')->nullable();
            $table->dateTime('reviewed_at', 6)->nullable();
            $table->primary(['item_id', 'claimant_id']);
            $table->index(['outcome', 'submitted_at', 'item_id', 'claimant_id'], 'claim_outcome_submitted_item_claimant_idx');
            $table->foreign('item_id')->references('item_id')->on('found_item')->restrictOnDelete()->restrictOnUpdate();
            $table->foreign('claimant_id')->references('person_id')->on('person')->restrictOnDelete()->restrictOnUpdate();
            $table->foreign('reviewed_by')->references('staff_id')->on('staff')->restrictOnDelete()->restrictOnUpdate();
        });

        DB::statement("ALTER TABLE `person` ADD CONSTRAINT `person_full_name_nonblank` CHECK (CHAR_LENGTH(TRIM(`full_name`)) > 0), ADD CONSTRAINT `person_contact_details_nonblank` CHECK (CHAR_LENGTH(TRIM(`contact_details`)) > 0)");
        DB::statement("ALTER TABLE `staff` ADD CONSTRAINT `staff_full_name_nonblank` CHECK (CHAR_LENGTH(TRIM(`full_name`)) > 0)");
        DB::statement("ALTER TABLE `category` ADD CONSTRAINT `category_name_nonblank` CHECK (CHAR_LENGTH(TRIM(`category_name`)) > 0)");
        DB::statement("ALTER TABLE `storage_location` ADD CONSTRAINT `storage_location_code_nonblank` CHECK (CHAR_LENGTH(TRIM(`location_code`)) > 0), ADD CONSTRAINT `storage_location_description_nonblank` CHECK (CHAR_LENGTH(TRIM(`location_description`)) > 0)");
        DB::statement("ALTER TABLE `found_item` ADD CONSTRAINT `found_item_description_nonblank` CHECK (CHAR_LENGTH(TRIM(`item_description`)) > 0), ADD CONSTRAINT `found_item_place_nonblank` CHECK (CHAR_LENGTH(TRIM(`found_place`)) > 0), ADD CONSTRAINT `found_item_found_before_received` CHECK (`found_at` <= `received_at`)");
        DB::statement("ALTER TABLE `storage_assignment` ADD CONSTRAINT `storage_assignment_no_positive` CHECK (`assignment_no` > 0), ADD CONSTRAINT `storage_assignment_removed_after_assigned` CHECK (`removed_at` IS NULL OR `removed_at` > `assigned_at`)");
        DB::statement("ALTER TABLE `claim` ADD CONSTRAINT `claim_evidence_nonblank` CHECK (CHAR_LENGTH(TRIM(`evidence_text`)) > 0), ADD CONSTRAINT `claim_outcome_valid` CHECK (BINARY `outcome` IN ('PENDING', 'REJECTED', 'RETURNED')), ADD CONSTRAINT `claim_review_fields_match_outcome` CHECK ((BINARY `outcome` = 'PENDING' AND `reviewed_by` IS NULL AND `reviewed_at` IS NULL) OR (BINARY `outcome` IN ('REJECTED', 'RETURNED') AND `reviewed_by` IS NOT NULL AND `reviewed_at` IS NOT NULL AND `reviewed_at` >= `submitted_at`))");
        $databaseVersion = strtolower((string) DB::selectOne('SELECT VERSION() AS version')->version);
        $isMariaDb = str_contains($databaseVersion, 'mariadb');

        if ($isMariaDb) {
            DB::statement('CREATE INDEX `found_item_category_received_item_idx` ON `found_item` (`category_id`, `received_at`, `item_id`)');
            DB::statement('ALTER TABLE `storage_assignment` ADD COLUMN `open_item_id` BIGINT UNSIGNED GENERATED ALWAYS AS (CASE WHEN `removed_at` IS NULL THEN `item_id` ELSE NULL END) VIRTUAL');
            DB::statement("ALTER TABLE `claim` ADD COLUMN `returned_item_id` BIGINT UNSIGNED GENERATED ALWAYS AS (CASE WHEN BINARY `outcome` = 'RETURNED' THEN `item_id` ELSE NULL END) VIRTUAL");
            DB::statement('CREATE UNIQUE INDEX `storage_assignment_one_open_item_unique` ON `storage_assignment` (`open_item_id`)');
            DB::statement('CREATE UNIQUE INDEX `claim_one_returned_item_unique` ON `claim` (`returned_item_id`)');
        } else {
            DB::statement('CREATE INDEX `found_item_category_received_item_idx` ON `found_item` (`category_id`, `received_at` DESC, `item_id`)');
            DB::statement('CREATE UNIQUE INDEX `storage_assignment_one_open_item_unique` ON `storage_assignment` ((CASE WHEN `removed_at` IS NULL THEN `item_id` ELSE NULL END))');
            DB::statement("CREATE UNIQUE INDEX `claim_one_returned_item_unique` ON `claim` ((CASE WHEN `outcome` = 'RETURNED' THEN `item_id` ELSE NULL END))");
        }


    }

    public function down(): void
    {
        Schema::dropIfExists('claim');
        Schema::dropIfExists('storage_assignment');
        Schema::dropIfExists('found_item');
        Schema::dropIfExists('storage_location');
        Schema::dropIfExists('category');
        Schema::dropIfExists('staff');
        Schema::dropIfExists('person');
    }
};
