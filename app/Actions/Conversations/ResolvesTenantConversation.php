<?php

namespace App\Actions\Conversations;

use App\Actions\Tenancy\ResolveTenantRuntimeConnectionAction;
use App\Models\CommunicationConversation;
use App\Support\Tenancy\CurrentTenantConnection;

trait ResolvesTenantConversation
{
    public function __construct(
        private readonly ResolveTenantRuntimeConnectionAction $resolveTenantRuntimeConnection,
        private readonly CurrentTenantConnection $currentTenantConnection,
    ) {}

    protected function withTenantContext(?string $tenantId, callable $callback): mixed
    {
        $hadTenantContext = $this->currentTenantConnection->connectionName() !== null;
        $this->resolveTenantRuntimeConnection->handle($tenantId);

        try {
            return $callback();
        } finally {
            if (! $hadTenantContext) {
                $this->currentTenantConnection->clear();
            }
        }
    }

    protected function conversation(string $conversationId, ?string $tenantId): CommunicationConversation
    {
        $query = CommunicationConversation::query()
            ->where('id', $conversationId);

        if ($tenantId === null || $tenantId === '') {
            $query->whereNull('tenant_id');
        } else {
            $query->where('tenant_id', $tenantId);
        }

        return $query
            ->firstOrFail();
    }
}
