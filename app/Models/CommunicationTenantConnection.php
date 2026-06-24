<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CommunicationTenantConnection extends Model
{
    use HasUuids;

    protected $fillable = [
        'communication_tenant_id',
        'connection_name',
        'database_host',
        'database_port',
        'database_name',
        'database_username',
        'database_password_encrypted',
        'database_driver',
        'status',
        'migrated_at',
        'metadata',
    ];

    protected function casts(): array
    {
        return [
            'database_port' => 'integer',
            'migrated_at' => 'datetime',
            'metadata' => 'array',
        ];
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(CommunicationTenant::class, 'communication_tenant_id');
    }
}
