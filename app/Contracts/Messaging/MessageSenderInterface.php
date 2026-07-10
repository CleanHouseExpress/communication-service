<?php

namespace App\Contracts\Messaging;

use App\DTO\Messaging\MessagePayload;
use App\DTO\Messaging\MessageResult;

interface MessageSenderInterface
{
    public function sendText(MessagePayload $payload): MessageResult;

    public function sendImage(MessagePayload $payload): MessageResult;

    public function sendDocument(MessagePayload $payload): MessageResult;

    public function sendAudio(MessagePayload $payload): MessageResult;
}
