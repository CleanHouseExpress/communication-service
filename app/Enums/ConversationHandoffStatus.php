<?php

namespace App\Enums;

enum ConversationHandoffStatus: string
{
    case None = 'none';
    case Requested = 'requested';
    case Assigned = 'assigned';
}
