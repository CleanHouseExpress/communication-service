<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('communication_conversation_events', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('tenant_id')->nullable();
            $table->uuid('conversation_id');
            $table->uuid('message_id')->nullable();
            $table->uuid('agent_run_id')->nullable();
            $table->string('event_type');
            $table->string('actor_type');
            $table->string('actor_id')->nullable();
            $table->string('actor_name')->nullable();
            $table->text('description')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamp('occurred_at');
            $table->timestamp('created_at')->nullable();

            $table->index('tenant_id', 'comm_conv_events_tenant_idx');
            $table->index('conversation_id', 'comm_conv_events_conv_idx');
            $table->index('event_type', 'comm_conv_events_type_idx');
            $table->index('occurred_at', 'comm_conv_events_occurred_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('communication_conversation_events');
    }
};
