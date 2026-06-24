<?php

namespace App\Support\Tenancy;

trait UsesCurrentTenantConnection
{
    public function getConnectionName()
    {
        if ((bool) config('communication.tenancy.runtime.enabled', false)) {
            $connectionName = app(CurrentTenantConnection::class)->connectionName();

            if (is_string($connectionName) && $connectionName !== '') {
                return $connectionName;
            }
        }

        return parent::getConnectionName();
    }
}
