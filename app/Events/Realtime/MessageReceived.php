<?php

namespace App\Events\Realtime;

class MessageReceived extends AbstractCommunicationRealtimeEvent
{
    protected function eventName(): string
    {
        return 'message.received';
    }
}
