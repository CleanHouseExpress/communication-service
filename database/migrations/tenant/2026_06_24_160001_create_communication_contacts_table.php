<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('communication_contacts', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('tenant_id')->nullable()->index('cct_tenant_idx');
            $table->string('provider', 50)->index('cct_provider_idx');
            $table->string('external_id');
            $table->string('name')->nullable();
            $table->string('phone')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->unique(['tenant_id', 'provider', 'external_id'], 'cct_tenant_provider_external_uq');
            $table->index(['provider', 'external_id'], 'cct_provider_external_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('communication_contacts');
    }
};
