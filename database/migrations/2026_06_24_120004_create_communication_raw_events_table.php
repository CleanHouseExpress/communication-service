<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('communication_raw_events', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('provider', 50)->index();
            $table->string('external_event_id')->nullable();
            $table->string('external_message_id')->nullable();
            $table->string('tenant_id')->nullable()->index();
            $table->uuid('channel_id')->nullable();
            $table->json('payload');
            $table->json('normalized_payload')->nullable();
            $table->timestamp('processed_at')->nullable()->index();
            $table->timestamps();

            $table->unique(['provider', 'external_event_id'], 'communication_raw_events_provider_event_unique');
            $table->index(['provider', 'external_message_id']);
            $table->index(['tenant_id', 'provider']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('communication_raw_events');
    }
};
