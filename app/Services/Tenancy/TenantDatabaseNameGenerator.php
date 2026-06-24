<?php

namespace App\Services\Tenancy;

use App\Models\CommunicationTenant;

class TenantDatabaseNameGenerator
{
    private const MAX_LENGTH = 64;

    public function generate(CommunicationTenant $tenant): string
    {
        $prefix = $this->sanitizePrefix((string) config('communication.tenancy.database_provisioning.prefix', 'communication_tenant_'));
        $source = $tenant->slug ?: $tenant->orchestra_tenant_id ?: $tenant->id;
        $suffix = $this->sanitizeIdentifier($source);

        if ($suffix === '') {
            $suffix = 'tenant_'.$this->sanitizeIdentifier($tenant->id);
        }

        $databaseName = $prefix.$suffix;

        return substr($databaseName, 0, self::MAX_LENGTH);
    }

    private function sanitizePrefix(string $prefix): string
    {
        $prefix = strtolower($prefix);
        $prefix = preg_replace('/[^a-z0-9_]+/', '_', $prefix) ?? '';
        $prefix = trim($prefix, '_');

        return $prefix !== '' ? $prefix.'_' : 'communication_tenant_';
    }

    private function sanitizeIdentifier(string $value): string
    {
        $value = strtolower($value);
        $value = preg_replace('/[^a-z0-9_]+/', '_', $value) ?? '';
        $value = trim($value, '_');

        return preg_replace('/_+/', '_', $value) ?? '';
    }
}
