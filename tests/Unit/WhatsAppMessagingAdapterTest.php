<?php

namespace Tests\Unit;

use App\DTO\Messaging\MessagePayload;
use App\Services\Messaging\WhatsAppChannelStatusChecker;
use App\Services\Messaging\WhatsAppMessageSender;
use Clin\MessagingSdk\DTO\EvolutionResponse;
use Clin\MessagingSdk\MessagingClient;
use Clin\MessagingSdk\Providers\Evolution\Resources\InstanceResource;
use Clin\MessagingSdk\Providers\Evolution\Resources\MessageResource;
use Mockery;
use Tests\TestCase;

class WhatsAppMessagingAdapterTest extends TestCase
{
    public function test_sends_text_using_messaging_sdk(): void
    {
        $messages = new class extends MessageResource {
            public function __construct()
            {
            }

            public function sendText(string $instanceName, string $number, string $text): EvolutionResponse
            {
                TestCase::assertSame('clin', $instanceName);
                TestCase::assertSame('5511999999999', $number);
                TestCase::assertSame('Teste via Communication Service', $text);

                return new EvolutionResponse(true, ['key' => ['id' => 'msg-text-1']]);
            }
        };

        $sender = new WhatsAppMessageSender($this->clientWithMessages($messages));
        $result = $sender->sendText(new MessagePayload('clin', '5511999999999', 'Teste via Communication Service'));

        $this->assertTrue($result->success);
        $this->assertSame('msg-text-1', $result->providerMessageId);
        $this->assertSame('sent', $result->status);
    }

    public function test_sends_image_using_messaging_sdk(): void
    {
        $messages = new class extends MessageResource {
            public function __construct()
            {
            }

            public function sendImage(string $instanceName, string $number, string $mediaUrl, ?string $caption = null): EvolutionResponse
            {
                TestCase::assertSame('clin', $instanceName);
                TestCase::assertSame('5511999999999', $number);
                TestCase::assertSame('https://cdn.test/image.jpg', $mediaUrl);
                TestCase::assertSame('Legenda', $caption);

                return new EvolutionResponse(true, ['key' => ['id' => 'msg-image-1']]);
            }
        };

        $sender = new WhatsAppMessageSender($this->clientWithMessages($messages));
        $result = $sender->sendImage(new MessagePayload('clin', '5511999999999', mediaUrl: 'https://cdn.test/image.jpg', caption: 'Legenda'));

        $this->assertTrue($result->success);
        $this->assertSame('msg-image-1', $result->providerMessageId);
    }

    public function test_sends_document_using_messaging_sdk(): void
    {
        $messages = new class extends MessageResource {
            public function __construct()
            {
            }

            public function sendDocument(string $instanceName, string $number, string $mediaUrl, ?string $caption = null, ?string $fileName = null): EvolutionResponse
            {
                TestCase::assertSame('clin', $instanceName);
                TestCase::assertSame('5511999999999', $number);
                TestCase::assertSame('https://cdn.test/doc.pdf', $mediaUrl);
                TestCase::assertSame('Contrato', $caption);
                TestCase::assertSame('doc.pdf', $fileName);

                return new EvolutionResponse(true, ['key' => ['id' => 'msg-document-1']]);
            }
        };

        $sender = new WhatsAppMessageSender($this->clientWithMessages($messages));
        $result = $sender->sendDocument(new MessagePayload('clin', '5511999999999', mediaUrl: 'https://cdn.test/doc.pdf', caption: 'Contrato', fileName: 'doc.pdf'));

        $this->assertTrue($result->success);
        $this->assertSame('msg-document-1', $result->providerMessageId);
    }

    public function test_sends_audio_using_messaging_sdk(): void
    {
        $messages = new class extends MessageResource {
            public function __construct()
            {
            }

            public function sendAudio(string $instanceName, string $number, string $mediaUrl): EvolutionResponse
            {
                TestCase::assertSame('clin', $instanceName);
                TestCase::assertSame('5511999999999', $number);
                TestCase::assertSame('https://cdn.test/audio.mp3', $mediaUrl);

                return new EvolutionResponse(true, ['key' => ['id' => 'msg-audio-1']]);
            }
        };

        $sender = new WhatsAppMessageSender($this->clientWithMessages($messages));
        $result = $sender->sendAudio(new MessagePayload('clin', '5511999999999', mediaUrl: 'https://cdn.test/audio.mp3'));

        $this->assertTrue($result->success);
        $this->assertSame('msg-audio-1', $result->providerMessageId);
    }

    public function test_checks_instance_status_using_messaging_sdk(): void
    {
        $instances = new class extends InstanceResource {
            public function __construct()
            {
            }

            public function connectionState(string $instanceName): EvolutionResponse
            {
                TestCase::assertSame('clin', $instanceName);

                return new EvolutionResponse(true, ['state' => 'open']);
            }
        };

        $client = Mockery::mock(MessagingClient::class);
        $client->shouldReceive('instances')->once()->andReturn($instances);

        $result = (new WhatsAppChannelStatusChecker($client))->check('clin');

        $this->assertTrue($result->success);
        $this->assertSame('open', $result->status);
    }

    private function clientWithMessages(MessageResource $messages): MessagingClient
    {
        $client = Mockery::mock(MessagingClient::class);
        $client->shouldReceive('messages')->once()->andReturn($messages);

        return $client;
    }
}
