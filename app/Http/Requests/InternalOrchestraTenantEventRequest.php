<?php

namespace App\Http\Requests;

use App\Enums\CommunicationTenantStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class InternalOrchestraTenantEventRequest extends FormRequest
{
    private const KNOWN_EVENTS = [
        'TenantCreated',
        'TenantUpdated',
        'TenantDisabled',
        'TenantEnabled',
    ];

    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'event_id' => ['required', 'string', 'max:120'],
            'event_type' => ['required', 'string', 'max:120'],
            'occurred_at' => ['nullable', 'date'],
            'tenant' => [Rule::requiredIf($this->isKnownEvent()), 'array'],
            'tenant.id' => [Rule::requiredIf($this->isKnownEvent()), 'string', 'max:100'],
            'tenant.name' => ['nullable', 'string', 'max:150'],
            'tenant.slug' => ['nullable', 'string', 'max:150'],
            'tenant.status' => ['nullable', Rule::enum(CommunicationTenantStatus::class)],
            'tenant.timezone' => ['nullable', 'string', 'max:80'],
            'tenant.metadata' => ['nullable', 'array', 'max:100'],
        ];
    }

    private function isKnownEvent(): bool
    {
        return in_array((string) $this->input('event_type'), self::KNOWN_EVENTS, true);
    }
}
