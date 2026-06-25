<?php

namespace App\Events\Realtime;

class ConversationUpdated extends AbstractCommunicationRealtimeEvent
{
    protected function eventName(): string
    {
        return 'conversation.updated';
    }
}
