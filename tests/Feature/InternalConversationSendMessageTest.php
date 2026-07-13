<?php

namespace Tests\Feature;

use App\Contracts\Messaging\MessageSenderInterface;
use App\DTO\Messaging\MessagePayload;
use App\DTO\Messaging\MessageResult;
use App\Models\CommunicationChannel;
use App\Models\CommunicationContact;
use App\Models\CommunicationConversation;
use App\Models\CommunicationMessage;
use App\Models\CommunicationOutboundMessage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class InternalConversationSendMessageTest extends TestCase
{
    use RefreshDatabase;

    public function test_requires_service_token(): void
    {
        $conversation = $this->conversation('tenant-1');

        $this->postJson("/api/internal/inbox/conversations/{$conversation->id}/messages", [
            'tenant_id' => 'tenant-1',
            'text' => 'Mensagem humana',
        ])->assertUnauthorized();
    }

    public function test_text_is_required(): void
    {
        config(['communication.service_token' => 'valid-token']);

        $conversation = $this->conversation('tenant-1');

        $this->withHeader('X-Service-Token', 'valid-token')
            ->postJson("/api/internal/inbox/conversations/{$conversation->id}/messages", [
                'tenant_id' => 'tenant-1',
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('text');
    }

    public function test_rejects_large_text(): void
    {
        config(['communication.service_token' => 'valid-token']);

        $conversation = $this->conversation('tenant-1');

        $this->withHeader('X-Service-Token', 'valid-token')
            ->postJson("/api/internal/inbox/conversations/{$conversation->id}/messages", [
                'tenant_id' => 'tenant-1',
                'text' => str_repeat('a', 4001),
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('text');
    }

    public function test_conversation_not_found_returns_404(): void
    {
        config(['communication.service_token' => 'valid-token']);

        $this->withHeader('X-Service-Token', 'valid-token')
            ->postJson('/api/internal/inbox/conversations/00000000-0000-4000-8000-000000000000/messages', [
                'tenant_id' => 'tenant-1',
                'text' => 'Mensagem humana',
            ])
            ->assertNotFound();
    }

    public function test_conversation_from_other_tenant_returns_404(): void
    {
        config(['communication.service_token' => 'valid-token']);

        $conversation = $this->conversation('tenant-2');

        $this->withHeader('X-Service-Token', 'valid-token')
            ->postJson("/api/internal/inbox/conversations/{$conversation->id}/messages", [
                'tenant_id' => 'tenant-1',
                'text' => 'Mensagem humana',
            ])
            ->assertNotFound();
    }

    public function test_closed_conversation_is_rejected(): void
    {
        config(['communication.service_token' => 'valid-token']);

        $conversation = $this->conversation('tenant-1', [
            'status' => 'closed',
            'closed_at' => now(),
        ]);

        $this->withHeader('X-Service-Token', 'valid-token')
            ->postJson("/api/internal/inbox/conversations/{$conversation->id}/messages", [
                'tenant_id' => 'tenant-1',
                'text' => 'Mensagem humana',
            ])
            ->assertConflict();
    }

    public function test_creates_outbound_message_and_marks_sent_with_fake_provider(): void
    {
        config([
            'communication.service_token' => 'valid-token',
            'communication.providers.zapi.fake' => true,
            'communication.providers.zapi.fake_failure' => false,
        ]);

        $conversation = $this->conversation('tenant-1');

        $this->withHeader('X-Service-Token', 'valid-token')
            ->postJson("/api/internal/inbox/conversations/{$conversation->id}/messages", [
                'tenant_id' => 'tenant-1',
                'text' => 'Mensagem do atendente',
            ])
            ->assertOk()
            ->assertJsonPath('data.tenant_id', 'tenant-1')
            ->assertJsonPath('data.conversation_id', $conversation->id)
            ->assertJsonPath('data.direction', 'outbound')
            ->assertJsonPath('data.message_type', 'text')
            ->assertJsonPath('data.text', 'Mensagem do atendente')
            ->assertJsonPath('data.status', 'sent');

        $message = CommunicationMessage::query()->where('conversation_id', $conversation->id)->firstOrFail();
        $outbound = CommunicationOutboundMessage::query()->where('communication_message_id', $message->id)->firstOrFail();

        $this->assertSame('outbound', $message->direction);
        $this->assertSame('sent', $message->status);
        $this->assertSame('sent', $outbound->status);
        $this->assertSame('5541999999999', $outbound->external_contact_id);
        $this->assertSame('human', $outbound->payload['source']);
        $this->assertNotEmpty($outbound->idempotency_key);
    }

    public function test_sends_whatsapp_conversation_message_through_messaging_sdk(): void
    {
        config(['communication.service_token' => 'valid-token']);

        $sender = new class implements MessageSenderInterface
        {
            public ?MessagePayload $payload = null;

            public function sendText(MessagePayload $payload): MessageResult
            {
                $this->payload = $payload;

                return new MessageResult(true, 'evolution', 'text', $payload->instanceName, 'evolution-message-1', 'sent');
            }

            public function sendImage(MessagePayload $payload): MessageResult
            {
                throw new \LogicException('Unexpected image send.');
            }

            public function sendDocument(MessagePayload $payload): MessageResult
            {
                throw new \LogicException('Unexpected document send.');
            }

            public function sendAudio(MessagePayload $payload): MessageResult
            {
                throw new \LogicException('Unexpected audio send.');
            }
        };
        $this->app->instance(MessageSenderInterface::class, $sender);

        $channel = CommunicationChannel::create([
            'tenant_id' => 'tenant-1',
            'provider' => 'whatsapp',
            'external_id' => 'orchestra-clin-4-whatsapp',
            'name' => 'WhatsApp',
            'status' => 'active',
        ]);
        $contact = CommunicationContact::create([
            'tenant_id' => 'tenant-1',
            'provider' => 'whatsapp',
            'external_id' => '5541999999999',
            'name' => 'Maria Cliente',
            'phone' => '5541999999999',
        ]);
        $conversation = CommunicationConversation::create([
            'tenant_id' => 'tenant-1',
            'channel_id' => $channel->id,
            'contact_id' => $contact->id,
            'status' => 'open',
            'last_message_at' => now(),
            'metadata' => [],
        ]);

        $this->withHeader('X-Service-Token', 'valid-token')
            ->postJson("/api/internal/inbox/conversations/{$conversation->id}/messages", [
                'tenant_id' => 'tenant-1',
                'text' => 'Mensagem via Evolution',
            ])
            ->assertOk()
            ->assertJsonPath('data.provider', 'whatsapp')
            ->assertJsonPath('data.status', 'sent');

        $this->assertSame('orchestra-clin-4-whatsapp', $sender->payload?->instanceName);
        $this->assertSame('5541999999999', $sender->payload?->number);
        $this->assertSame('Mensagem via Evolution', $sender->payload?->message);

        $message = CommunicationMessage::query()->where('conversation_id', $conversation->id)->firstOrFail();
        $outbound = CommunicationOutboundMessage::query()->where('communication_message_id', $message->id)->firstOrFail();

        $this->assertSame('whatsapp', $message->provider);
        $this->assertSame('whatsapp', $outbound->provider);
        $this->assertSame('sent', $outbound->status);
        $this->assertSame('evolution-message-1', $outbound->provider_message_id);
    }

    public function test_response_does_not_include_raw_or_provider_payloads(): void
    {
        config([
            'communication.service_token' => 'valid-token',
            'communication.providers.zapi.fake' => true,
        ]);

        $conversation = $this->conversation('tenant-1');

        $response = $this->withHeader('X-Service-Token', 'valid-token')
            ->postJson("/api/internal/inbox/conversations/{$conversation->id}/messages", [
                'tenant_id' => 'tenant-1',
                'text' => 'Mensagem segura',
            ])
            ->assertOk();

        $this->assertStringNotContainsString('provider_response', $response->getContent());
        $this->assertStringNotContainsString('payload', $response->getContent());
        $this->assertStringNotContainsString('client_token', $response->getContent());
    }

    private function conversation(string $tenantId, array $overrides = []): CommunicationConversation
    {
        $channel = CommunicationChannel::create([
            'tenant_id' => $tenantId,
            'provider' => 'zapi',
            'external_id' => 'channel-'.$tenantId.'-'.uniqid(),
            'name' => 'Z-API',
            'status' => 'active',
        ]);

        $contact = CommunicationContact::create([
            'tenant_id' => $tenantId,
            'provider' => 'zapi',
            'external_id' => '5541999999999',
            'name' => 'Maria Cliente',
            'phone' => '5541999999999',
        ]);

        return CommunicationConversation::create([
            ...[
                'tenant_id' => $tenantId,
                'channel_id' => $channel->id,
                'contact_id' => $contact->id,
                'status' => 'open',
                'last_message_at' => now(),
                'metadata' => [],
            ],
            ...$overrides,
        ]);
    }
}
