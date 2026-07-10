<?php

namespace App\Models;

use App\Support\Tenancy\UsesCurrentTenantConnection;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class CommunicationChannel extends Model
{
    use HasUuids;
    use UsesCurrentTenantConnection;

    protected $fillable = [
        'tenant_id',
        'provider',
        'external_id',
        'name',
        'type',
        'status',
        'settings',
        'provisioned_by_system',
        'provisioned_at',
        'provisioning_status',
        'provisioning_error',
        'expected_phone_number',
        'connected_phone_number',
        'last_connected_at',
        'last_disconnected_at',
        'last_status_check_at',
    ];

    protected function casts(): array
    {
        return [
            'settings' => 'array',
            'provisioned_by_system' => 'boolean',
            'provisioned_at' => 'datetime',
            'last_connected_at' => 'datetime',
            'last_disconnected_at' => 'datetime',
            'last_status_check_at' => 'datetime',
        ];
    }

    public function conversations(): HasMany
    {
        return $this->hasMany(CommunicationConversation::class, 'channel_id');
    }

    public function messages(): HasMany
    {
        return $this->hasMany(CommunicationMessage::class, 'channel_id');
    }
}
