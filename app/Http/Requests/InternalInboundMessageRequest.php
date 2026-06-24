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
            'tenant_id' => ['nullable', 'string', 'max:128'],
            'channel_id' => ['nullable', 'string', 'max:128'],
            'external_event_id' => ['nullable', 'string', 'max:255'],
            'external_message_id' => ['nullable', 'string', 'max:255'],
            'external_contact_id' => ['required', 'string', 'max:64'],
            'contact_name' => ['nullable', 'string', 'max:255'],
            'contact_phone' => ['nullable', 'string', 'max:64'],
            'message_type' => ['required', Rule::enum(MessageType::class)],
            'text' => ['nullable', 'string', 'max:4096'],
            'occurred_at' => ['nullable', 'date'],
            'raw_payload' => ['nullable', 'array', 'max:100'],
        ];
    }
}
