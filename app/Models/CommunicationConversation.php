<?php

namespace App\Models;

use App\Support\Tenancy\UsesCurrentTenantConnection;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class CommunicationConversation extends Model
{
    use HasUuids;
    use UsesCurrentTenantConnection;

    protected $fillable = [
        'tenant_id',
        'channel_id',
        'contact_id',
        'status',
        'last_message_at',
        'metadata',
    ];

    protected function casts(): array
    {
        return [
            'last_message_at' => 'datetime',
            'metadata' => 'array',
        ];
    }

    public function channel(): BelongsTo
    {
        return $this->belongsTo(CommunicationChannel::class, 'channel_id');
    }

    public function contact(): BelongsTo
    {
        return $this->belongsTo(CommunicationContact::class, 'contact_id');
    }

    public function messages(): HasMany
    {
        return $this->hasMany(CommunicationMessage::class, 'conversation_id');
    }
}
