<?php

namespace App\Events\Realtime;

class MessageStatusUpdated extends AbstractCommunicationRealtimeEvent
{
    protected function eventName(): string
    {
        return 'message.status_updated';
    }
}
