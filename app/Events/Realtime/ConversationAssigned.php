<?php

namespace App\Events\Realtime;

class ConversationAssigned extends AbstractCommunicationRealtimeEvent
{
    protected function eventName(): string
    {
        return 'conversation.assigned';
    }
}
