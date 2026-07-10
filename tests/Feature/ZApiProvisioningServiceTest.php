<?php

namespace Tests\Feature;

use App\Models\CommunicationChannel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class ZApiProvisioningServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_provisions_whatsapp_channel_with_partner_api_and_safe_response(): void
    {
        $this->configureRealZapi();

        Http::fake([
            'https://api.z-api.test/instances' => Http::response([
                'instanceId' => 'instance-1',
                'instanceToken' => 'instance-token-1',
            ], 201),
            'https://api.z-api.test/instances/instance-1/token/instance-token-1/update-webhook-*' => Http::response(['success' => true], 200),
            'https://api.z-api.test/instances/instance-1/token/instance-token-1/qr-code/image' => Http::response([
                'qrCode' => 'qr-code-value',
                'image' => 'data:image/png;base64,cXI=',
            ], 200),
        ]);

        $response = $this->withHeader('X-Service-Token', 'valid-token')
            ->postJson('/api/tenant/communication/channels/provision-whatsapp', [
                'tenant_id' => 'tenant-1',
                'name' => 'WhatsApp Suporte',
                'expected_phone_number' => '+55 41 99999-9999',
                'default_department_id' => 'department-1',
                'default_assignee_id' => 'user-1',
            ]);

        $response->assertCreated()
            ->assertJsonPath('channel.provider', 'zapi')
            ->assertJsonPath('channel.type', 'whatsapp')
            ->assertJsonPath('channel.status', 'qr_pending')
            ->assertJsonPath('channel.provisioned_by_system', true)
            ->assertJsonPath('channel.provisioning_status', 'completed')
            ->assertJsonPath('connection.status', 'qr_pending')
            ->assertJsonPath('connection.qr_code', 'qr-code-value');

        $content = $response->getContent();
        $this->assertStringNotContainsString('instance-token-1', $content);
        $this->assertStringNotContainsString('client-secret', $content);
        $this->assertStringNotContainsString('partner-secret', $content);

        $channel = CommunicationChannel::query()->firstOrFail();
        $this->assertSame('tenant-1', $channel->tenant_id);
        $this->assertSame('instance-1', $channel->external_id);
        $this->assertSame('department-1', $channel->settings['default_department_id']);
        $this->assertSame('user-1', $channel->settings['default_assignee_id']);
        $this->assertNotSame('instance-token-1', $channel->settings['zapi']['instance_token']);
        $this->assertSame('instance-token-1', Crypt::decryptString($channel->settings['zapi']['instance_token']));
        $this->assertNotNull($channel->last_status_check_at);

        Http::assertSent(function ($request): bool {
            return $request->url() === 'https://api.z-api.test/instances'
                && $request->hasHeader('Authorization', 'Bearer partner-secret')
                && ! $request->hasHeader('Client-Token');
        });

        Http::assertSent(function ($request) use ($channel): bool {
            return $request->url() === 'https://api.z-api.test/instances/instance-1/token/instance-token-1/update-webhook-received'
                && $request['value'] === "https://hooks.example.test/api/webhooks/z-api/{$channel->id}/messages"
                && $request->hasHeader('Client-Token', 'client-secret');
        });
    }

    public function test_provision_endpoint_requires_service_token(): void
    {
        config(['communication.service_token' => 'valid-token']);

        $this->postJson('/api/tenant/communication/channels/provision-whatsapp')
            ->assertUnauthorized();
    }

    public function test_partner_api_failure_marks_channel_as_error_without_leaking_tokens(): void
    {
        $this->configureRealZapi();

        Http::fake([
            'https://api.z-api.test/instances' => Http::response([
                'error' => true,
                'message' => 'partner failure with token=instance-token-1',
            ], 500),
        ]);

        $response = $this->withHeader('X-Service-Token', 'valid-token')
            ->postJson('/api/tenant/communication/channels/provision-whatsapp', [
                'tenant_id' => 'tenant-1',
                'name' => 'WhatsApp Suporte',
            ]);

        $response->assertStatus(422);
        $this->assertStringNotContainsString('partner-secret', $response->getContent());
        $this->assertStringNotContainsString('instance-token-1', $response->getContent());

        $this->assertDatabaseHas('communication_channels', [
            'tenant_id' => 'tenant-1',
            'status' => 'error',
            'provisioning_status' => 'instance_creation_failed',
        ]);
    }

    public function test_webhook_configuration_failure_marks_channel_as_error(): void
    {
        $this->configureRealZapi();

        Http::fake([
            'https://api.z-api.test/instances' => Http::response([
                'instanceId' => 'instance-1',
                'instanceToken' => 'instance-token-1',
            ], 201),
            'https://api.z-api.test/instances/instance-1/token/instance-token-1/update-webhook-received' => Http::response(['success' => false], 500),
            'https://api.z-api.test/instances/instance-1/token/instance-token-1/update-webhook-*' => Http::response(['success' => true], 200),
        ]);

        $response = $this->withHeader('X-Service-Token', 'valid-token')
            ->postJson('/api/tenant/communication/channels/provision-whatsapp', [
                'tenant_id' => 'tenant-1',
                'name' => 'WhatsApp Suporte',
            ]);

        $response->assertStatus(422);

        $this->assertDatabaseHas('communication_channels', [
            'tenant_id' => 'tenant-1',
            'status' => 'error',
            'provisioning_status' => 'webhook_configuration_failed',
        ]);
    }

    private function configureRealZapi(): void
    {
        config([
            'communication.service_token' => 'valid-token',
            'communication.providers.zapi.fake' => false,
            'communication.providers.zapi.base_url' => null,
            'communication.providers.zapi.api_url' => 'https://api.z-api.test',
            'communication.providers.zapi.partner_token' => 'partner-secret',
            'communication.providers.zapi.client_token' => 'client-secret',
            'communication.providers.zapi.webhook_base_url' => 'https://hooks.example.test',
            'communication.providers.zapi.paths.create_instance' => '/instances',
        ]);
    }
}
