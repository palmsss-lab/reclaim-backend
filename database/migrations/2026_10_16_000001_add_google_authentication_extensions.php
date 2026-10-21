<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', fn (Blueprint $table) => $table->boolean('has_local_password')->default(true));
        Schema::create('google_identities', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->unique()->constrained()->restrictOnDelete();
            $table->string('subject', 255)->unique()->charset('ascii')->collation('ascii_bin');
            $table->timestamps();
        });
        Schema::create('google_auth_attempts', function (Blueprint $table) {
            $table->string('state_hash', 64)->primary();
            $table->string('challenge', 43);
            $table->text('provider_verifier');
            $table->foreignId('user_id')->nullable()->constrained()->restrictOnDelete();
            $table->unsignedBigInteger('token_id')->nullable();
            $table->timestamp('expires_at')->index();
            $table->timestamp('callback_used_at')->nullable();
            $table->string('exchange_hash', 64)->nullable()->unique();
            $table->text('profile')->nullable();
        });
        Schema::create('password_setup_tokens', function (Blueprint $table) {
            $table->foreignId('user_id')->primary()->constrained()->restrictOnDelete();
            $table->string('token_hash', 64)->unique();
            $table->string('email');
            $table->timestamp('expires_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('password_setup_tokens');
        Schema::dropIfExists('google_auth_attempts');
        Schema::dropIfExists('google_identities');
        Schema::table('users', fn (Blueprint $table) => $table->dropColumn('has_local_password'));
    }
};
