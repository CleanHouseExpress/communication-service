<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class CommunicationChannel extends Model
{
    use HasUuids;

    protected $fillable = [
        'tenant_id',
        'provider',
        'external_id',
        'name',
        'status',
        'settings',
    ];

    protected function casts(): array
    {
        return [
            'settings' => 'array',
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
