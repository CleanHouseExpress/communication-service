<?php

namespace App\Events\Realtime;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

abstract class AbstractCommunicationRealtimeEvent implements ShouldBroadcast, ShouldDispatchAfterCommit, ShouldQueue
{
    use Dispatchable;
    use InteractsWithSockets;
    use SerializesModels;

    public function __construct(
        public readonly string $tenantId,
        public readonly string $conversationId,
        public readonly array $resource,
        public readonly string $occurredAt,
    ) {}

    public function broadcastOn(): array
    {
        return [
            new PrivateChannel("tenant.{$this->tenantId}.communication"),
            new PrivateChannel("conversation.{$this->conversationId}"),
        ];
    }

    public function broadcastAs(): string
    {
        return $this->eventName();
    }

    public function broadcastQueue(): string
    {
        return (string) config('communication.realtime.queue', 'communication-realtime');
    }

    public function broadcastWith(): array
    {
        return [
            'tenant_id' => $this->tenantId,
            'conversation_id' => $this->conversationId,
            'event' => $this->eventName(),
            'timestamp' => $this->occurredAt,
            'resource' => $this->resource,
        ];
    }

    abstract protected function eventName(): string;
}
