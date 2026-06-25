<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('communication_conversations', function (Blueprint $table): void {
            $table->string('service_mode')->default('ai')->after('status');
            $table->string('handoff_status')->default('none')->after('service_mode');
            $table->string('handoff_requested_by')->nullable()->after('handoff_reason');
            $table->text('handoff_requested_reason')->nullable()->after('handoff_requested_by');
            $table->timestamp('handoff_assigned_at')->nullable()->after('assigned_at');

            $table->index('service_mode', 'comm_conv_service_mode_idx');
            $table->index('handoff_status', 'comm_conv_handoff_status_idx');
        });
    }

    public function down(): void
    {
        Schema::table('communication_conversations', function (Blueprint $table): void {
            $table->dropIndex('comm_conv_service_mode_idx');
            $table->dropIndex('comm_conv_handoff_status_idx');
            $table->dropColumn([
                'service_mode',
                'handoff_status',
                'handoff_requested_by',
                'handoff_requested_reason',
                'handoff_assigned_at',
            ]);
        });
    }
};
