<?php

namespace App\Events\Realtime;

class ConversationHandoffRequested extends AbstractCommunicationRealtimeEvent
{
    protected function eventName(): string
    {
        return 'conversation.handoff_requested';
    }
}
