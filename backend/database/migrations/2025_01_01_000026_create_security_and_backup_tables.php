<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->text('mfa_secret')->nullable();               // encrypted (cast); pending until mfa_confirmed_at is set
            $table->timestamp('mfa_confirmed_at')->nullable();
            $table->text('mfa_recovery_codes')->nullable();       // encrypted JSON list of keyed hashes; each code works once
            $table->unsignedBigInteger('mfa_last_step')->nullable(); // last accepted TOTP time-step (blocks replay)
            $table->unsignedSmallInteger('failed_login_count')->default(0);
            $table->timestamp('locked_until')->nullable();
            $table->timestamp('password_changed_at')->nullable();
            $table->timestamp('last_login_at')->nullable();
            $table->string('last_login_ip', 45)->nullable();
        });

        // One "session" = a login: its access + refresh token share a session_key, so it can be listed and revoked as one.
        Schema::table('personal_access_tokens', function (Blueprint $table) {
            $table->string('session_key', 36)->nullable()->index();
            $table->string('ip_address', 45)->nullable();
            $table->string('user_agent', 255)->nullable();
        });

        Schema::create('backup_runs', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('status', 12)->default('running'); // running | ok | failed
            $table->string('trigger', 12)->default('schedule'); // schedule | manual
            $table->string('filename', 160)->nullable();
            $table->unsignedBigInteger('size_bytes')->nullable();
            $table->string('sha256', 64)->nullable();         // of the encrypted file, as stored
            $table->boolean('encrypted')->default(false);
            $table->string('db_driver', 20)->nullable();
            $table->boolean('includes_media')->default(false);
            $table->string('offsite_disk', 40)->nullable();
            $table->boolean('offsite_ok')->nullable();
            $table->string('error', 500)->nullable();
            $table->timestamp('verified_at')->nullable();
            $table->foreignUuid('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();
            $table->index(['status', 'started_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('backup_runs');
        Schema::table('personal_access_tokens', fn (Blueprint $t) => $t->dropColumn(['session_key', 'ip_address', 'user_agent']));
        Schema::table('users', fn (Blueprint $t) => $t->dropColumn([
            'mfa_secret', 'mfa_confirmed_at', 'mfa_recovery_codes', 'mfa_last_step', 'failed_login_count', 'locked_until',
            'password_changed_at', 'last_login_at', 'last_login_ip',
        ]));
    }
};
