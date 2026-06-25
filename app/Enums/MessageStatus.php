<?php

namespace App\Enums;

enum MessageStatus: string
{
    case Pending = 'pending';
    case Sending = 'sending';
    case Received = 'received';
    case Processing = 'processing';
    case Sent = 'sent';
    case Delivered = 'delivered';
    case Read = 'read';
    case Failed = 'failed';
}
