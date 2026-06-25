<?php

namespace App\Events\Realtime;

class ConversationReopened extends AbstractCommunicationRealtimeEvent
{
    protected function eventName(): string
    {
        return 'conversation.reopened';
    }
}
