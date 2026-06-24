<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('communication_messages', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('tenant_id')->nullable()->index();
            $table->foreignUuid('conversation_id')->constrained('communication_conversations')->cascadeOnDelete();
            $table->foreignUuid('contact_id')->nullable()->constrained('communication_contacts')->nullOnDelete();
            $table->foreignUuid('channel_id')->nullable()->constrained('communication_channels')->nullOnDelete();
            $table->string('provider', 50)->index();
            $table->string('external_message_id')->nullable();
            $table->string('direction', 30)->index();
            $table->string('message_type', 30)->index();
            $table->text('text')->nullable();
            $table->json('payload')->nullable();
            $table->string('status', 30)->default('received')->index();
            $table->timestamp('occurred_at')->nullable()->index();
            $table->timestamps();

            $table->unique(['provider', 'external_message_id'], 'communication_messages_provider_external_unique');
            $table->index(['conversation_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('communication_messages');
    }
};
