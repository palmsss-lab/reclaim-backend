<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement("ALTER TABLE `finder_report` ADD CONSTRAINT `finder_report_dates_ordered` CHECK (`found_at` <= `submitted_at`), ADD CONSTRAINT `finder_report_handover_matches_status` CHECK ((BINARY `status` = 'RECEIVED' AND `received_item_id` IS NOT NULL) OR (BINARY `status` <> 'RECEIVED' AND `received_item_id` IS NULL))");
        DB::statement("ALTER TABLE `missing_item_report` ADD CONSTRAINT `missing_report_not_future_dated` CHECK (`lost_at` <= `created_at`)");
        DB::statement('ALTER TABLE `claim` ADD INDEX `claim_user_submitted_item_idx` (`claimant_id`, `submitted_at`, `item_id`)');
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE `claim` DROP INDEX `claim_user_submitted_item_idx`');
        DB::statement('ALTER TABLE `missing_item_report` DROP CHECK `missing_report_not_future_dated`');
        DB::statement('ALTER TABLE `finder_report` DROP CHECK `finder_report_handover_matches_status`, DROP CHECK `finder_report_dates_ordered`');
    }
};