<?php

namespace App\Events\Realtime;

class ConversationReturnedToAi extends AbstractCommunicationRealtimeEvent
{
    protected function eventName(): string
    {
        return 'conversation.returned_to_ai';
    }
}
