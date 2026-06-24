<?php

namespace App\Http\Requests;

use App\Enums\MessageType;
use App\Enums\ProviderType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class InternalInboundMessageRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'provider' => ['required', Rule::enum(ProviderType::class)],
            'tenant_id' => ['nullable', 'string'],
            'channel_id' => ['nullable', 'string'],
            'external_event_id' => ['nullable', 'string'],
            'external_message_id' => ['nullable', 'string'],
            'external_contact_id' => ['required', 'string'],
            'contact_name' => ['nullable', 'string'],
            'contact_phone' => ['nullable', 'string'],
            'message_type' => ['required', Rule::enum(MessageType::class)],
            'text' => ['nullable', 'string'],
            'occurred_at' => ['nullable', 'date'],
            'raw_payload' => ['nullable', 'array'],
        ];
    }
}
