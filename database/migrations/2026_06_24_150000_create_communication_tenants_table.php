<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('communication_tenants', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('orchestra_tenant_id', 100)->unique();
            $table->string('name', 150)->nullable();
            $table->string('slug', 150)->nullable();
            $table->string('status', 30)->default('active')->index();
            $table->string('timezone', 80)->nullable();
            $table->json('metadata')->nullable();
            $table->timestamp('synced_at')->nullable();
            $table->timestamp('disabled_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('communication_tenants');
    }
};
