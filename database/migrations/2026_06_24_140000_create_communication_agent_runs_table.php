<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('communication_agent_runs', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('tenant_id')->nullable()->index();
            $table->foreignUuid('conversation_id')->nullable()->constrained('communication_conversations')->nullOnDelete();
            $table->foreignUuid('message_id')->nullable()->constrained('communication_messages')->nullOnDelete();
            $table->string('provider', 50)->nullable();
            $table->string('agent', 50)->default('n8n');
            $table->string('status', 30)->default('pending')->index();
            $table->json('request_payload')->nullable();
            $table->json('response_payload')->nullable();
            $table->text('response_text')->nullable();
            $table->text('failed_reason')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();

            $table->index('conversation_id');
            $table->index('message_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('communication_agent_runs');
    }
};
