<?php

namespace App\Enums;

enum CommunicationTenantStatus: string
{
    case Active = 'active';
    case Disabled = 'disabled';
    case Pending = 'pending';
}
