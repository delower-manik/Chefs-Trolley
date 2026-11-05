<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Consent: nobody who opted out is ever messaged by a campaign or an announcement.
        Schema::table('customers', function (Blueprint $table) {
            $table->boolean('marketing_opt_out')->default(false)->after('is_active');
            $table->timestamp('marketing_opt_out_at')->nullable()->after('marketing_opt_out');
        });

        Schema::create('gift_cards', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('branch_id')->nullable()->constrained()->nullOnDelete(); // issuing branch; redeemable anywhere
            // The code is the secret (it works like cash): only a hash is stored, the code is shown once at issue.
            $table->string('code_hash', 64)->unique();
            $table->string('code_last4', 4);
            $table->decimal('initial_value', 12, 2);
            $table->decimal('balance', 12, 2);
            $table->enum('status', ['active', 'void'])->default('active');
            $table->timestamp('expires_at')->nullable();
            $table->foreignUuid('customer_id')->nullable()->constrained()->nullOnDelete();
            $table->string('recipient_name', 120)->nullable();
            $table->string('note', 255)->nullable();
            $table->string('sold_method', 20)->nullable();
            $table->string('sold_reference', 100)->nullable();
            $table->foreignUuid('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index(['status', 'expires_at']);
        });

        Schema::create('gift_card_entries', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('gift_card_id')->constrained()->cascadeOnDelete();
            $table->string('kind', 12); // issue | redeem | refund | adjust | void
            $table->decimal('amount', 12, 2); // signed
            $table->decimal('balance_after', 12, 2);
            $table->foreignUuid('order_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignUuid('payment_id')->nullable()->constrained()->nullOnDelete();
            $table->string('reason', 200)->nullable();
            $table->foreignUuid('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('created_at')->useCurrent();
            $table->index(['gift_card_id', 'created_at']);
        });

        Schema::create('customer_segments', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('branch_id')->nullable()->constrained()->nullOnDelete();
            $table->string('name', 120);
            $table->string('description', 255)->nullable();
            $table->string('match', 3)->default('all'); // all | any
            $table->json('rules');
            $table->foreignUuid('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('campaigns', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('branch_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignUuid('segment_id')->nullable()->constrained('customer_segments')->nullOnDelete();
            $table->string('name', 120);
            $table->string('title', 120);
            $table->text('body');
            $table->json('channels'); // push | sms | email
            $table->enum('status', ['draft', 'scheduled', 'sending', 'sent', 'cancelled'])->default('draft');
            $table->timestamp('scheduled_at')->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->unsignedInteger('audience_size')->default(0);
            $table->json('queued')->nullable(); // {push, sms, email}
            $table->foreignUuid('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index(['status', 'scheduled_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('campaigns');
        Schema::dropIfExists('customer_segments');
        Schema::dropIfExists('gift_card_entries');
        Schema::dropIfExists('gift_cards');
        Schema::table('customers', fn (Blueprint $t) => $t->dropColumn(['marketing_opt_out', 'marketing_opt_out_at']));
    }
};
