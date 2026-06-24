<?php

namespace App\Queries\Inbox;

use App\Actions\Tenancy\ResolveTenantRuntimeConnectionAction;
use App\Models\CommunicationConversation;
use App\Support\Tenancy\CurrentTenantConnection;

class ShowConversationQuery
{
    public function __construct(
        private readonly ResolveTenantRuntimeConnectionAction $resolveTenantRuntimeConnection,
        private readonly CurrentTenantConnection $currentTenantConnection,
    ) {}

    public function handle(string $conversationId, string $tenantId): CommunicationConversation
    {
        $hadTenantContext = $this->currentTenantConnection->connectionName() !== null;
        $this->resolveTenantRuntimeConnection->handle($tenantId);

        try {
            return CommunicationConversation::query()
                ->with([
                    'contact',
                    'messages' => fn ($query) => $query->latest('created_at')->limit(1),
                ])
                ->where('tenant_id', $tenantId)
                ->where('id', $conversationId)
                ->firstOrFail();
        } finally {
            if (! $hadTenantContext) {
                $this->currentTenantConnection->clear();
            }
        }
    }
}
