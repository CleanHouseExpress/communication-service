<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('communication_channels', function (Blueprint $table): void {
            if (! Schema::hasColumn('communication_channels', 'type')) {
                $table->string('type', 40)->default('whatsapp')->after('name');
            }
            if (! Schema::hasColumn('communication_channels', 'provisioned_by_system')) {
                $table->boolean('provisioned_by_system')->default(false)->after('settings');
            }
            if (! Schema::hasColumn('communication_channels', 'provisioned_at')) {
                $table->timestamp('provisioned_at')->nullable()->after('provisioned_by_system');
            }
            if (! Schema::hasColumn('communication_channels', 'provisioning_status')) {
                $table->string('provisioning_status', 40)->nullable()->after('provisioned_at');
            }
            if (! Schema::hasColumn('communication_channels', 'provisioning_error')) {
                $table->text('provisioning_error')->nullable()->after('provisioning_status');
            }
            if (! Schema::hasColumn('communication_channels', 'expected_phone_number')) {
                $table->string('expected_phone_number', 40)->nullable()->after('provisioning_error');
            }
            if (! Schema::hasColumn('communication_channels', 'connected_phone_number')) {
                $table->string('connected_phone_number', 40)->nullable()->after('expected_phone_number');
            }
            if (! Schema::hasColumn('communication_channels', 'last_status_check_at')) {
                $table->timestamp('last_status_check_at')->nullable()->after('last_disconnected_at');
            }
        });
    }

    public function down(): void
    {
        Schema::table('communication_channels', function (Blueprint $table): void {
            $table->dropColumn([
                'type',
                'provisioned_by_system',
                'provisioned_at',
                'provisioning_status',
                'provisioning_error',
                'expected_phone_number',
                'connected_phone_number',
                'last_status_check_at',
            ]);
        });
    }
};
