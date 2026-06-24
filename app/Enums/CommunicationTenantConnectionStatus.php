<?php

namespace App\Enums;

enum CommunicationTenantConnectionStatus: string
{
    case Pending = 'pending';
    case Active = 'active';
    case Failed = 'failed';
    case Disabled = 'disabled';
}
