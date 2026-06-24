<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('communication_integration_events', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('source')->default('orchestra-api');
            $table->string('event_id', 120);
            $table->string('event_type', 120)->index();
            $table->string('aggregate_type')->nullable();
            $table->string('aggregate_id')->nullable()->index();
            $table->json('payload');
            $table->string('status', 30)->default('processed')->index();
            $table->timestamp('processed_at')->nullable();
            $table->text('failed_reason')->nullable();
            $table->timestamps();

            $table->unique(['source', 'event_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('communication_integration_events');
    }
};
