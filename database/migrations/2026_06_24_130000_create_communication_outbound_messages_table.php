<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('communication_outbound_messages', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('tenant_id')->nullable()->index();
            $table->foreignUuid('channel_id')->nullable()->constrained('communication_channels')->nullOnDelete();
            $table->foreignUuid('conversation_id')->nullable()->constrained('communication_conversations')->nullOnDelete();
            $table->foreignUuid('contact_id')->nullable()->constrained('communication_contacts')->nullOnDelete();
            $table->foreignUuid('communication_message_id')->nullable()->constrained('communication_messages')->nullOnDelete();
            $table->string('provider', 50)->index();
            $table->string('external_contact_id');
            $table->string('idempotency_key')->unique();
            $table->string('message_type', 30);
            $table->text('text')->nullable();
            $table->json('payload')->nullable();
            $table->string('status', 30)->default('pending')->index();
            $table->string('provider_message_id')->nullable()->index();
            $table->json('provider_response')->nullable();
            $table->text('failed_reason')->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('communication_outbound_messages');
    }
};
