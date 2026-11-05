<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Laravel's database queue (works on shared hosting: a cron runs the worker).
        Schema::create('jobs', function (Blueprint $table) {
            $table->id();
            $table->string('queue')->index();
            $table->longText('payload');
            $table->unsignedTinyInteger('attempts');
            $table->unsignedInteger('reserved_at')->nullable();
            $table->unsignedInteger('available_at');
            $table->unsignedInteger('created_at');
        });

        Schema::create('failed_jobs', function (Blueprint $table) {
            $table->id();
            $table->string('uuid')->unique();
            $table->text('connection');
            $table->text('queue');
            $table->longText('payload');
            $table->longText('exception');
            $table->timestamp('failed_at')->useCurrent();
        });

        Schema::create('notification_deliveries', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('branch_id')->nullable();
            $table->string('channel', 10);            // sms | email | push
            $table->string('recipient');              // phone, email address or device token
            $table->uuid('customer_id')->nullable();
            $table->string('subject')->nullable();
            $table->text('body');
            $table->string('purpose', 40)->default('custom');
            $table->string('reference_type')->nullable();
            $table->uuid('reference_id')->nullable();
            $table->string('status', 10)->default('queued'); // queued | sent | failed
            $table->string('provider', 30)->nullable();
            $table->string('provider_message_id')->nullable();
            $table->unsignedTinyInteger('attempts')->default(0);
            $table->text('error')->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->timestamps();

            $table->index(['status', 'created_at']);
            $table->index(['branch_id', 'created_at']);
            $table->index(['reference_type', 'reference_id']);
        });

        Schema::create('device_tokens', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('owner_type');
            $table->uuid('owner_id');
            $table->string('token', 500)->unique();
            $table->string('platform', 10); // android | ios | web
            $table->timestamp('last_seen_at')->nullable();
            $table->timestamps();

            $table->index(['owner_type', 'owner_id']);
        });

        Schema::create('webhook_deliveries', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('webhook_id');
            $table->string('event', 40);
            $table->uuid('event_id'); // stable across retries; receivers dedupe on it
            $table->longText('payload');
            $table->string('status', 10)->default('queued'); // queued | sent | failed
            $table->unsignedTinyInteger('attempts')->default(0);
            $table->unsignedSmallInteger('response_status')->nullable();
            $table->string('response_excerpt', 300)->nullable();
            $table->text('error')->nullable();
            $table->timestamp('next_attempt_at')->nullable();
            $table->timestamp('delivered_at')->nullable();
            $table->timestamps();

            $table->foreign('webhook_id')->references('id')->on('webhooks')->cascadeOnDelete();
            $table->index(['webhook_id', 'created_at']);
            $table->index(['status', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('webhook_deliveries');
        Schema::dropIfExists('device_tokens');
        Schema::dropIfExists('notification_deliveries');
        Schema::dropIfExists('failed_jobs');
        Schema::dropIfExists('jobs');
    }
};
