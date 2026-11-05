<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('audit_logs', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('actor_id')->nullable();
            $table->string('actor_name')->nullable(); // snapshot in case actor is later deleted
            $table->string('action'); // e.g. "order.cancelled", "menu_item.updated"
            $table->string('subject_type');
            $table->uuid('subject_id');
            $table->uuid('branch_id')->nullable();
            $table->json('changes')->nullable(); // {before: {...}, after: {...}}
            $table->string('ip_address')->nullable();
            $table->timestamp('created_at');

            $table->foreign('actor_id')->references('id')->on('users')->nullOnDelete();
            $table->foreign('branch_id')->references('id')->on('branches')->nullOnDelete();
            $table->index(['subject_type', 'subject_id']);
            $table->index('action');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('audit_logs');
    }
};
