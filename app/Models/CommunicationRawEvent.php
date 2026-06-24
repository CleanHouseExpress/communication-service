<?php

namespace App\Models;

use App\Support\Tenancy\UsesCurrentTenantConnection;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CommunicationRawEvent extends Model
{
    use HasUuids;
    use UsesCurrentTenantConnection;

    protected $fillable = [
        'provider',
        'external_event_id',
        'external_message_id',
        'tenant_id',
        'channel_id',
        'payload',
        'normalized_payload',
        'processed_at',
    ];

    protected function casts(): array
    {
        return [
            'payload' => 'array',
            'normalized_payload' => 'array',
            'processed_at' => 'datetime',
        ];
    }

    public function channel(): BelongsTo
    {
        return $this->belongsTo(CommunicationChannel::class, 'channel_id');
    }
}
