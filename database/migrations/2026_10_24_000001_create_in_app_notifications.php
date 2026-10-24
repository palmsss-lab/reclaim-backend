<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('in_app_notification', function (Blueprint $table): void {
            $table->bigIncrements('notification_id');
            // Account metadata may be removed with its account; domain history is unchanged.
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete()->restrictOnUpdate();
            $table->string('event_key', 160);
            $table->string('kind', 40);
            $table->string('target_type', 30);
            $table->unsignedBigInteger('target_id');
            $table->unsignedBigInteger('item_id')->nullable();
            $table->string('message', 255);
            $table->dateTime('read_at', 6)->nullable();
            $table->dateTime('created_at', 6);
            $table->unique(['user_id', 'event_key'], 'notification_owner_event_unique');
            $table->index(['user_id', 'created_at', 'notification_id'], 'notification_owner_recent');
            $table->index(['user_id', 'read_at', 'notification_id'], 'notification_owner_unread');
        });
    }

    public function down(): void { Schema::dropIfExists('in_app_notification'); }
};
