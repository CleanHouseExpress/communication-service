<?php

namespace App\Events\Realtime;

class ConversationClosed extends AbstractCommunicationRealtimeEvent
{
    protected function eventName(): string
    {
        return 'conversation.closed';
    }
}
