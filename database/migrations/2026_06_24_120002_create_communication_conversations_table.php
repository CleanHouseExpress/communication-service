<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('communication_conversations', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('tenant_id')->nullable()->index();
            $table->foreignUuid('channel_id')->nullable()->constrained('communication_channels')->nullOnDelete();
            $table->foreignUuid('contact_id')->constrained('communication_contacts')->cascadeOnDelete();
            $table->string('status', 30)->default('open')->index();
            $table->timestamp('last_message_at')->nullable()->index();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->index(['tenant_id', 'channel_id', 'status']);
            $table->index(['contact_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('communication_conversations');
    }
};
