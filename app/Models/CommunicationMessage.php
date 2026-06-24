<?php

namespace App\Models;

use App\Support\Tenancy\UsesCurrentTenantConnection;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CommunicationMessage extends Model
{
    use HasUuids;
    use UsesCurrentTenantConnection;

    protected $fillable = [
        'tenant_id',
        'conversation_id',
        'contact_id',
        'channel_id',
        'provider',
        'external_message_id',
        'direction',
        'message_type',
        'text',
        'payload',
        'status',
        'occurred_at',
    ];

    protected function casts(): array
    {
        return [
            'payload' => 'array',
            'occurred_at' => 'datetime',
        ];
    }

    public function conversation(): BelongsTo
    {
        return $this->belongsTo(CommunicationConversation::class, 'conversation_id');
    }

    public function contact(): BelongsTo
    {
        return $this->belongsTo(CommunicationContact::class, 'contact_id');
    }

    public function channel(): BelongsTo
    {
        return $this->belongsTo(CommunicationChannel::class, 'channel_id');
    }
}
