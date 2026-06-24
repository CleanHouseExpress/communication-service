<?php

namespace App\Enums;

enum IntegrationEventStatus: string
{
    case Received = 'received';
    case Processed = 'processed';
    case Failed = 'failed';
    case Ignored = 'ignored';
}
