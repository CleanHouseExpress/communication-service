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
            $table->string('tenant_id')->nullable()->index();
            $table->string('provider', 50)->index();
            $table->string('external_id')->nullable();
            $table->string('name')->nullable();
            $table->string('status', 30)->default('active')->index();
            $table->json('settings')->nullable();
            $table->timestamps();

            $table->index(['tenant_id', 'provider']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('communication_channels');
    }
};
