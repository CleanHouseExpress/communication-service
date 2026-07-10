<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('communication_channels', function (Blueprint $table): void {
            $table->timestamp('last_connected_at')->nullable()->after('settings');
            $table->timestamp('last_disconnected_at')->nullable()->after('last_connected_at');
        });
    }

    public function down(): void
    {
        Schema::table('communication_channels', function (Blueprint $table): void {
            $table->dropColumn(['last_connected_at', 'last_disconnected_at']);
        });
    }
};
