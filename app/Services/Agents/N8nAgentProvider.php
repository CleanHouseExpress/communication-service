<?php

namespace App\Services\Agents;

use App\Contracts\Agents\AgentProviderInterface;
use RuntimeException;

class N8nAgentProvider implements AgentProviderInterface
{
    public function shouldHandle(array $message): bool
    {
        return (bool) config('communication.agent.enabled') && ! empty($message);
    }

    public function sendToAgent(array $message): array
    {
        if (! app()->environment(['local', 'testing'])) {
            throw new RuntimeException('n8n agent bridge is not implemented yet.');
        }

        return [
            'provider' => 'n8n',
            'status' => 'accepted',
            'message' => $message,
        ];
    }
}
