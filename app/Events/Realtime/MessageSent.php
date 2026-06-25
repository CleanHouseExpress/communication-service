<?php

namespace App\Events\Realtime;

class MessageSent extends AbstractCommunicationRealtimeEvent
{
    protected function eventName(): string
    {
        return 'message.sent';
    }
}
