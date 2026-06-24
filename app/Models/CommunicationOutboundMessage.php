<?php

namespace App\Models;

use App\Support\Tenancy\UsesCurrentTenantConnection;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CommunicationOutboundMessage extends Model
{
    use HasUuids;
    use UsesCurrentTenantConnection;

    protected $fillable = [
        'tenant_id',
        'channel_id',
        'conversation_id',
        'contact_id',
        'communication_message_id',
        'provider',
        'external_contact_id',
        'idempotency_key',
        'message_type',
        'text',
        'payload',
        'status',
        'provider_message_id',
        'provider_response',
        'failed_reason',
        'sent_at',
    ];

    protected function casts(): array
    {
        return [
            'payload' => 'array',
            'provider_response' => 'array',
            'sent_at' => 'datetime',
        ];
    }

    public function channel(): BelongsTo
    {
        return $this->belongsTo(CommunicationChannel::class, 'channel_id');
    }

    public function conversation(): BelongsTo
    {
        return $this->belongsTo(CommunicationConversation::class, 'conversation_id');
    }

    public function contact(): BelongsTo
    {
        return $this->belongsTo(CommunicationContact::class, 'contact_id');
    }

    public function communicationMessage(): BelongsTo
    {
        return $this->belongsTo(CommunicationMessage::class, 'communication_message_id');
    }
}
