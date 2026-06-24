<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class CommunicationIntegrationEvent extends Model
{
    use HasUuids;

    protected $fillable = [
        'source',
        'event_id',
        'event_type',
        'aggregate_type',
        'aggregate_id',
        'payload',
        'status',
        'processed_at',
        'failed_reason',
    ];

    protected function casts(): array
    {
        return [
            'payload' => 'array',
            'processed_at' => 'datetime',
        ];
    }
}
