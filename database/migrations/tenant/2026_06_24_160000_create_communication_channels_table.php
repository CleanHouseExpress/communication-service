<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('communication_channels', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('tenant_id')->nullable()->index('cc_tenant_idx');
            $table->string('provider', 50)->index('cc_provider_idx');
            $table->string('external_id')->nullable();
            $table->string('name')->nullable();
            $table->string('status', 30)->default('active')->index('cc_status_idx');
            $table->json('settings')->nullable();
            $table->timestamps();

            $table->index(['tenant_id', 'provider'], 'cc_tenant_provider_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('communication_channels');
    }
};
