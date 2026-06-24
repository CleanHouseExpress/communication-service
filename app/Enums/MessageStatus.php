<?php

namespace App\Enums;

enum MessageStatus: string
{
    case Received = 'received';
    case Processing = 'processing';
    case Sent = 'sent';
    case Failed = 'failed';
}
