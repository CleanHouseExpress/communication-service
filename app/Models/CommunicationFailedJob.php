<?php

namespace App\Models;

use App\Support\Tenancy\UsesCurrentTenantConnection;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class CommunicationFailedJob extends Model
{
    use HasUuids;
    use UsesCurrentTenantConnection;

    public $timestamps = false;

    protected $table = 'communication_failed_jobs_metadata';

    protected $fillable = [
        'tenant_id',
        'job_name',
        'conversation_id',
        'message_id',
        'payload',
        'exception_class',
        'attempts',
        'failed_at',
        'resolved_at',
        'metadata',
    ];

    protected function casts(): array
    {
        return [
            'payload' => 'array',
            'attempts' => 'integer',
            'failed_at' => 'datetime',
            'resolved_at' => 'datetime',
            'metadata' => 'array',
        ];
    }
}
