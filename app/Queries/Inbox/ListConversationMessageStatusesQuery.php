<?php

namespace App\Queries\Inbox;

use App\Actions\Tenancy\ResolveTenantRuntimeConnectionAction;
use App\Models\CommunicationConversation;
use App\Models\CommunicationMessage;
use App\Support\Tenancy\CurrentTenantConnection;
use Illuminate\Database\Eloquent\Collection;

class ListConversationMessageStatusesQuery
{
    public function __construct(
        private readonly ResolveTenantRuntimeConnectionAction $resolveTenantRuntimeConnection,
        private readonly CurrentTenantConnection $currentTenantConnection,
    ) {}

    public function handle(string $conversationId, string $tenantId): Collection
    {
        $hadTenantContext = $this->currentTenantConnection->connectionName() !== null;
        $this->resolveTenantRuntimeConnection->handle($tenantId);

        try {
            CommunicationConversation::query()
                ->where('tenant_id', $tenantId)
                ->where('id', $conversationId)
                ->firstOrFail();

            return CommunicationMessage::query()
                ->where('tenant_id', $tenantId)
                ->where('conversation_id', $conversationId)
                ->orderBy('created_at')
                ->get();
        } finally {
            if (! $hadTenantContext) {
                $this->currentTenantConnection->clear();
            }
        }
    }
}
