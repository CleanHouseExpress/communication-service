<?php

namespace App\Contracts\Messaging;

use App\DTO\Messaging\ChannelStatusResult;

interface ChannelStatusCheckerInterface
{
    public function check(string $instanceName): ChannelStatusResult;
}
