<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('communication_conversations', function (Blueprint $table): void {
            $table->timestamp('handoff_requested_at')->nullable()->after('last_message_at');
            $table->text('handoff_reason')->nullable()->after('handoff_requested_at');
            $table->string('assigned_external_user_id')->nullable()->after('handoff_reason');
            $table->string('assigned_external_user_name')->nullable()->after('assigned_external_user_id');
            $table->timestamp('assigned_at')->nullable()->after('assigned_external_user_name');
            $table->timestamp('closed_at')->nullable()->after('assigned_at');
        });
    }

    public function down(): void
    {
        Schema::table('communication_conversations', function (Blueprint $table): void {
            $table->dropColumn([
                'handoff_requested_at',
                'handoff_reason',
                'assigned_external_user_id',
                'assigned_external_user_name',
                'assigned_at',
                'closed_at',
            ]);
        });
    }
};
