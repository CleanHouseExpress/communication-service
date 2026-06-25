<?php

namespace App\Events\Realtime;

class ConversationCreated extends AbstractCommunicationRealtimeEvent
{
    protected function eventName(): string
    {
        return 'conversation.created';
    }
}
