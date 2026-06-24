<?php

namespace App\Support\Tenancy;

use RuntimeException;

class TenantResolutionException extends RuntimeException
{
    public static function notFound(string $orchestraTenantId): self
    {
        return new self("Communication tenant [{$orchestraTenantId}] was not found.");
    }

    public static function inactive(string $orchestraTenantId): self
    {
        return new self("Communication tenant [{$orchestraTenantId}] is not active.");
    }
}
