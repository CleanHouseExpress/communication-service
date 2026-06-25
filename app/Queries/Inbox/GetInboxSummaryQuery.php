<?php

namespace App\Queries\Inbox;

use App\Actions\Tenancy\ResolveTenantRuntimeConnectionAction;
use App\Enums\ConversationStatus;
use App\Enums\MessageDirection;
use App\Models\CommunicationConversation;
use App\Models\CommunicationMessage;
use App\Support\Tenancy\CurrentTenantConnection;
use Illuminate\Database\Eloquent\Builder;

class GetInboxSummaryQuery
{
    public function __construct(
        private readonly ResolveTenantRuntimeConnectionAction $resolveTenantRuntimeConnection,
        private readonly CurrentTenantConnection $currentTenantConnection,
    ) {}

    public function handle(array $filters): array
    {
        $hadTenantContext = $this->currentTenantConnection->connectionName() !== null;
        $this->resolveTenantRuntimeConnection->handle($filters['tenant_id']);

        try {
            $baseQuery = CommunicationConversation::query()
                ->where('tenant_id', $filters['tenant_id']);

            $summary = [
                'total_open' => (clone $baseQuery)->where('status', ConversationStatus::Open->value)->count(),
                'total_pending' => (clone $baseQuery)->where('status', ConversationStatus::Pending->value)->count(),
                'total_closed' => (clone $baseQuery)->where('status', ConversationStatus::Closed->value)->count(),
                'total_unassigned' => (clone $baseQuery)->whereNull('assigned_external_user_id')->count(),
                'total_assigned' => (clone $baseQuery)->whereNotNull('assigned_external_user_id')->count(),
                'total_handoff_requested' => (clone $baseQuery)->whereNotNull('handoff_requested_at')->count(),
                'total_my_assigned' => null,
                'total_inbound_last_message' => $this->latestDirectionCount($baseQuery, MessageDirection::Inbound->value),
                'total_outbound_last_message' => $this->latestDirectionCount($baseQuery, MessageDirection::Outbound->value),
            ];

            if (! empty($filters['assigned_external_user_id'])) {
                $summary['total_my_assigned'] = (clone $baseQuery)
                    ->where('assigned_external_user_id', $filters['assigned_external_user_id'])
                    ->count();
            }

            return $summary;
        } finally {
            if (! $hadTenantContext) {
                $this->currentTenantConnection->clear();
            }
        }
    }

    private function latestDirectionCount(Builder $baseQuery, string $direction): int
    {
        return (clone $baseQuery)
            ->where(
                CommunicationMessage::query()
                    ->select('direction')
                    ->whereColumn('communication_messages.conversation_id', 'communication_conversations.id')
                    ->latest('occurred_at')
                    ->latest('created_at')
                    ->limit(1),
                $direction
            )
            ->count();
    }
}
