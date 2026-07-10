<?php

namespace App\Services\Providers;

use App\Enums\ProviderType;
use App\Exceptions\ZApiProvisioningException;
use App\Models\CommunicationChannel;
use App\Models\CommunicationTenant;
use App\Services\Security\PayloadSanitizer;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Log;

class ZApiProvisioningService
{
    public function __construct(
        private readonly ZApiProviderService $zapiProvider,
        private readonly PayloadSanitizer $payloadSanitizer,
    ) {}

    public function provision(array $payload): array
    {
        $tenantId = $this->stringValue($payload['tenant_id'] ?? null);
        $tenant = $this->tenant($tenantId);

        $channel = CommunicationChannel::create([
            'tenant_id' => $tenantId,
            'provider' => ProviderType::Zapi->value,
            'type' => 'whatsapp',
            'name' => $this->stringValue($payload['name'] ?? null) ?: 'WhatsApp Atendimento',
            'status' => 'provisioning',
            'settings' => $this->nonSensitiveSettings($payload),
            'provisioned_by_system' => true,
            'provisioned_at' => now(),
            'provisioning_status' => 'started',
            'expected_phone_number' => $this->stringValue($payload['expected_phone_number'] ?? null),
        ]);

        $this->log('Z-API provisioning started.', $channel, ['event' => 'provisioning_started']);

        $instance = $this->zapiProvider->createInstanceForTenant($tenant, $channel);
        if (! ($instance['success'] ?? false)) {
            $this->fail($channel, 'instance_creation_failed', (string) ($instance['error'] ?? 'Z-API instance could not be created.'));
        }

        $instanceId = $this->stringValue($instance['instance_id'] ?? null);
        $instanceToken = $this->stringValue($instance['instance_token'] ?? null);

        if ($instanceId === null || $instanceToken === null) {
            $this->fail($channel, 'instance_creation_failed', 'Z-API instance credentials were not returned.');
        }

        $settings = $channel->settings ?? [];
        Arr::set($settings, 'zapi.instance_id', Crypt::encryptString($instanceId));
        Arr::set($settings, 'zapi.instance_token', Crypt::encryptString($instanceToken));

        $channel->forceFill([
            'external_id' => $instanceId,
            'settings' => $settings,
            'provisioning_status' => 'credentials_saved',
            'provisioning_error' => null,
        ])->save();

        $this->log('Z-API credentials saved.', $channel, ['event' => 'credentials_saved']);

        $webhooks = $this->zapiProvider->configureWebhooks($channel);
        if (! ($webhooks['success'] ?? false)) {
            $this->fail($channel, 'webhook_configuration_failed', (string) ($webhooks['error'] ?? 'Z-API webhooks could not be configured.'));
        }

        $qr = $this->zapiProvider->getQrCode($channel);
        if (! ($qr['success'] ?? false)) {
            $this->fail($channel, 'qr_generation_failed', (string) ($qr['error'] ?? 'Z-API QR code could not be generated.'));
        }

        $channel->forceFill([
            'status' => 'qr_pending',
            'provisioning_status' => 'completed',
            'provisioning_error' => null,
            'last_status_check_at' => now(),
        ])->save();

        $this->log('Z-API provisioning completed.', $channel, ['event' => 'provisioning_completed']);

        return [
            'channel' => $this->safeChannel($channel->refresh()),
            'connection' => [
                'status' => 'qr_pending',
                'qr_code' => $qr['qr_code'] ?? null,
                'image' => $qr['image'] ?? null,
            ],
        ];
    }

    private function tenant(?string $tenantId): ?CommunicationTenant
    {
        if ($tenantId === null) {
            return null;
        }

        return CommunicationTenant::query()
            ->where('orchestra_tenant_id', $tenantId)
            ->first();
    }

    private function nonSensitiveSettings(array $payload): array
    {
        return array_filter([
            'default_department_id' => $this->stringValue($payload['default_department_id'] ?? null),
            'default_assignee_id' => $this->stringValue($payload['default_assignee_id'] ?? null),
        ], static fn ($value): bool => $value !== null);
    }

    private function safeChannel(CommunicationChannel $channel): array
    {
        return [
            'id' => $channel->id,
            'tenant_id' => $channel->tenant_id,
            'provider' => $channel->provider,
            'type' => $channel->type,
            'name' => $channel->name,
            'status' => $channel->status,
            'provisioned_by_system' => $channel->provisioned_by_system,
            'provisioned_at' => $channel->provisioned_at?->toIso8601String(),
            'provisioning_status' => $channel->provisioning_status,
            'expected_phone_number' => $channel->expected_phone_number,
            'connected_phone_number' => $channel->connected_phone_number,
            'last_connected_at' => $channel->last_connected_at?->toIso8601String(),
            'last_disconnected_at' => $channel->last_disconnected_at?->toIso8601String(),
            'last_status_check_at' => $channel->last_status_check_at?->toIso8601String(),
            'settings' => [
                'default_department_id' => $channel->settings['default_department_id'] ?? null,
                'default_assignee_id' => $channel->settings['default_assignee_id'] ?? null,
            ],
        ];
    }

    private function fail(CommunicationChannel $channel, string $status, string $error): never
    {
        $safeError = $this->safeError($error);

        $channel->forceFill([
            'status' => 'error',
            'provisioning_status' => $status,
            'provisioning_error' => $safeError,
            'last_status_check_at' => now(),
        ])->save();

        $this->log('Z-API provisioning failed.', $channel, [
            'event' => 'provisioning_failed',
            'provisioning_status' => $status,
            'error' => $safeError,
        ], warning: true);

        throw new ZApiProvisioningException($safeError);
    }

    private function stringValue(mixed $value): ?string
    {
        return is_scalar($value) && trim((string) $value) !== '' ? trim((string) $value) : null;
    }

    private function safeError(string $error): string
    {
        $sanitized = $this->payloadSanitizer->sanitize(['error' => $error]);

        return substr((string) ($sanitized['error'] ?? 'Z-API provisioning failed.'), 0, 300);
    }

    private function log(string $message, CommunicationChannel $channel, array $context = [], bool $warning = false): void
    {
        $payload = $this->payloadSanitizer->sanitize([
            'tenant_id' => $channel->tenant_id,
            'channel_id' => $channel->id,
            'provider' => 'zapi',
            ...$context,
        ]);

        $warning ? Log::warning($message, $payload) : Log::info($message, $payload);
    }
}
