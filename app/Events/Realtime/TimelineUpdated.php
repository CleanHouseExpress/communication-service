<?php

namespace App\Events\Realtime;

class TimelineUpdated extends AbstractCommunicationRealtimeEvent
{
    protected function eventName(): string
    {
        return 'timeline.updated';
    }
}
