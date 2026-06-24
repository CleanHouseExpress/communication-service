<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('communication_tenant_connections', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('communication_tenant_id')->constrained('communication_tenants')->cascadeOnDelete();
            $table->string('connection_name')->nullable();
            $table->string('database_host')->nullable();
            $table->integer('database_port')->nullable();
            $table->string('database_name')->nullable();
            $table->string('database_username')->nullable();
            $table->text('database_password_encrypted')->nullable();
            $table->string('database_driver', 30)->default('mysql');
            $table->string('status', 30)->default('pending')->index();
            $table->timestamp('migrated_at')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('communication_tenant_connections');
    }
};
