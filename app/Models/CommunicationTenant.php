<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class CommunicationTenant extends Model
{
    use HasUuids;

    protected $fillable = [
        'orchestra_tenant_id',
        'name',
        'slug',
        'status',
        'timezone',
        'metadata',
        'synced_at',
        'disabled_at',
    ];

    protected function casts(): array
    {
        return [
            'metadata' => 'array',
            'synced_at' => 'datetime',
            'disabled_at' => 'datetime',
        ];
    }

    public function connections(): HasMany
    {
        return $this->hasMany(CommunicationTenantConnection::class, 'communication_tenant_id');
    }
}
