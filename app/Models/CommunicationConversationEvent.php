<?php

namespace App\Models;

use App\Support\Tenancy\UsesCurrentTenantConnection;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CommunicationConversationEvent extends Model
{
    use HasUuids;
    use UsesCurrentTenantConnection;

    public $timestamps = false;

    protected $fillable = [
        'tenant_id',
        'conversation_id',
        'message_id',
        'agent_run_id',
        'event_type',
        'actor_type',
        'actor_id',
        'actor_name',
        'description',
        'metadata',
        'occurred_at',
        'created_at',
    ];

    protected function casts(): array
    {
        return [
            'metadata' => 'array',
            'occurred_at' => 'datetime',
            'created_at' => 'datetime',
        ];
    }

    public function conversation(): BelongsTo
    {
        return $this->belongsTo(CommunicationConversation::class, 'conversation_id');
    }

    public function message(): BelongsTo
    {
        return $this->belongsTo(CommunicationMessage::class, 'message_id');
    }

    public function agentRun(): BelongsTo
    {
        return $this->belongsTo(CommunicationAgentRun::class, 'agent_run_id');
    }
}
