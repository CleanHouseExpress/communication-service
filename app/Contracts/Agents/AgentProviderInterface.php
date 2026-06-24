<?php

namespace App\Contracts\Agents;

interface AgentProviderInterface
{
    public function shouldHandle(array $message): bool;

    public function sendToAgent(array $message): array;
}
