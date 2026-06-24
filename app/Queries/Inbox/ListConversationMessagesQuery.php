<?php

namespace App\Queries\Inbox;

use App\Actions\Tenancy\ResolveTenantRuntimeConnectionAction;
use App\Models\CommunicationConversation;
use App\Models\CommunicationMessage;
use App\Support\Tenancy\CurrentTenantConnection;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

class ListConversationMessagesQuery
{
    public function __construct(
        private readonly ResolveTenantRuntimeConnectionAction $resolveTenantRuntimeConnection,
        private readonly CurrentTenantConnection $currentTenantConnection,
    ) {}

    public function handle(string $conversationId, array $filters): LengthAwarePaginator
    {
        $hadTenantContext = $this->currentTenantConnection->connectionName() !== null;
        $this->resolveTenantRuntimeConnection->handle($filters['tenant_id']);

        try {
            CommunicationConversation::query()
                ->where('tenant_id', $filters['tenant_id'])
                ->where('id', $conversationId)
                ->firstOrFail();

            $query = CommunicationMessage::query()
                ->where('tenant_id', $filters['tenant_id'])
                ->where('conversation_id', $conversationId);

            if (! empty($filters['direction'])) {
                $query->where('direction', $filters['direction']);
            }

            if (! empty($filters['message_type'])) {
                $query->where('message_type', $filters['message_type']);
            }

            return $query
                ->orderByDesc('occurred_at')
                ->orderByDesc('created_at')
                ->paginate((int) ($filters['per_page'] ?? 25));
        } finally {
            if (! $hadTenantContext) {
                $this->currentTenantConnection->clear();
            }
        }
    }
}
