<?php

namespace App\Models;

use App\Support\Tenancy\UsesCurrentTenantConnection;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class CommunicationContact extends Model
{
    use HasUuids;
    use UsesCurrentTenantConnection;

    protected $fillable = [
        'tenant_id',
        'provider',
        'external_id',
        'name',
        'phone',
        'metadata',
    ];

    protected function casts(): array
    {
        return [
            'metadata' => 'array',
        ];
    }

    public function conversations(): HasMany
    {
        return $this->hasMany(CommunicationConversation::class, 'contact_id');
    }

    public function messages(): HasMany
    {
        return $this->hasMany(CommunicationMessage::class, 'contact_id');
    }
}
