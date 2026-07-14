<?php

namespace App\Queries\Inbox;

use App\Actions\Tenancy\ResolveTenantRuntimeConnectionAction;
use App\Models\CommunicationContact;
use App\Support\Tenancy\CurrentTenantConnection;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

class ListContactsQuery
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
            $query = CommunicationContact::query()
                ->where('tenant_id', $filters['tenant_id'])
                ->orderBy('name')
                ->orderBy('phone');

            if (! empty($filters['search'])) {
                $search = trim((string) $filters['search']);
                $query->where(function ($query) use ($search): void {
                    $query->where('name', 'like', "%{$search}%")
                        ->orWhere('phone', 'like', "%{$search}%")
                        ->orWhere('external_id', 'like', "%{$search}%");
                });
            }

            return $query->paginate((int) ($filters['per_page'] ?? 10));
        } finally {
            if (! $hadTenantContext) {
                $this->currentTenantConnection->clear();
            }
        }
    }
}
