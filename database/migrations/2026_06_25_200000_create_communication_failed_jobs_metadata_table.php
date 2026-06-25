<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('communication_failed_jobs_metadata', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('tenant_id')->nullable();
            $table->string('job_name');
            $table->uuid('conversation_id')->nullable();
            $table->uuid('message_id')->nullable();
            $table->json('payload')->nullable();
            $table->string('exception_class');
            $table->unsignedInteger('attempts')->default(1);
            $table->timestamp('failed_at');
            $table->timestamp('resolved_at')->nullable();
            $table->json('metadata')->nullable();

            $table->index('tenant_id', 'comm_failed_tenant_idx');
            $table->index('job_name', 'comm_failed_job_idx');
            $table->index('conversation_id', 'comm_failed_conv_idx');
            $table->index('failed_at', 'comm_failed_at_idx');
            $table->index('resolved_at', 'comm_failed_resolved_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('communication_failed_jobs_metadata');
    }
};
