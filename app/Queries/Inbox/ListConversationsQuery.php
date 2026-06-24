<?php

namespace App\Queries\Inbox;

use App\Actions\Tenancy\ResolveTenantRuntimeConnectionAction;
use App\Models\CommunicationConversation;
use App\Models\CommunicationMessage;
use App\Support\Tenancy\CurrentTenantConnection;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

class ListConversationsQuery
{
    public function __construct(
        private readonly ResolveTenantRuntimeConnectionAction $resolveTenantRuntimeConnection,
        private readonly CurrentTenantConnection $currentTenantConnection,
    ) {}

    public function handle(array $filters): LengthAwarePaginator
    {
        $hadTenantContext = $this->currentTenantConnection->connectionName() !== null;
        $this->resolveTenantRuntimeConnection->handle($filters['tenant_id']);

        try {
            $query = CommunicationConversation::query()
                ->with([
                    'contact',
                    'messages' => fn ($query) => $query->latest('created_at')->limit(1),
                ])
                ->where('tenant_id', $filters['tenant_id']);

            if (! empty($filters['status'])) {
                $query->where('status', $filters['status']);
            }

            if (! empty($filters['assignment_status'])) {
                $filters['assignment_status'] === 'assigned'
                    ? $query->whereNotNull('assigned_external_user_id')
                    : $query->whereNull('assigned_external_user_id');
            }

            if (! empty($filters['assigned_external_user_id'])) {
                $query->where('assigned_external_user_id', $filters['assigned_external_user_id']);
            }

            if (! empty($filters['handoff'])) {
                $filters['handoff'] === 'requested'
                    ? $query->whereNotNull('handoff_requested_at')
                    : $query->whereNull('handoff_requested_at');
            }

            if (array_key_exists('has_handoff_requested', $filters)) {
                filter_var($filters['has_handoff_requested'], FILTER_VALIDATE_BOOLEAN)
                    ? $query->whereNotNull('handoff_requested_at')
                    : $query->whereNull('handoff_requested_at');
            }

            if (array_key_exists('closed', $filters)) {
                filter_var($filters['closed'], FILTER_VALIDATE_BOOLEAN)
                    ? $query->whereNotNull('closed_at')
                    : $query->whereNull('closed_at');
            }

            if (! empty($filters['last_message_from'])) {
                $query->where(
                    CommunicationMessage::query()
                        ->select('direction')
                        ->whereColumn('communication_messages.conversation_id', 'communication_conversations.id')
                        ->latest('occurred_at')
                        ->latest('created_at')
                        ->limit(1),
                    $filters['last_message_from']
                );
            }

            if (! empty($filters['updated_since'])) {
                $query->where('updated_at', '>=', $filters['updated_since']);
            }

            if (! empty($filters['contact_id'])) {
                $query->where('contact_id', $filters['contact_id']);
            }

            if (! empty($filters['channel_id'])) {
                $query->where('channel_id', $filters['channel_id']);
            }

            if (! empty($filters['search'])) {
                $search = $filters['search'];

                $query->where(function ($query) use ($search): void {
                    $query->whereHas('contact', function ($query) use ($search): void {
                        $query->where('name', 'like', "%{$search}%")
                            ->orWhere('phone', 'like', "%{$search}%")
                            ->orWhere('external_id', 'like', "%{$search}%");
                    })->orWhereHas('messages', function ($query) use ($search): void {
                        $query->where('text', 'like', "%{$search}%");
                    });
                });
            }

            $sort = $filters['sort'] ?? 'last_message_at';
            $direction = $filters['direction'] ?? 'desc';

            return $query
                ->orderBy($sort, $direction)
                ->when($sort !== 'created_at', fn (Builder $query) => $query->orderByDesc('created_at'))
                ->paginate((int) ($filters['per_page'] ?? 15));
        } finally {
            if (! $hadTenantContext) {
                $this->currentTenantConnection->clear();
            }
        }
    }
}
