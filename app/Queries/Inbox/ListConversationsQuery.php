<?php

namespace App\Queries\Inbox;

use App\Actions\Tenancy\ResolveTenantRuntimeConnectionAction;
use App\Models\CommunicationConversation;
use App\Support\Tenancy\CurrentTenantConnection;
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

            return $query
                ->orderByDesc('last_message_at')
                ->orderByDesc('created_at')
                ->paginate((int) ($filters['per_page'] ?? 15));
        } finally {
            if (! $hadTenantContext) {
                $this->currentTenantConnection->clear();
            }
        }
    }
}
