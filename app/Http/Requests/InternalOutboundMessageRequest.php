<?php

namespace App\Http\Requests;

use App\Enums\MessageType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class InternalOutboundMessageRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'tenant_id' => ['required', 'string'],
            'channel_id' => ['required', 'uuid', 'exists:communication_channels,id'],
            'conversation_id' => ['required', 'uuid', 'exists:communication_conversations,id'],
            'contact_id' => ['required', 'uuid', 'exists:communication_contacts,id'],
            'external_contact_id' => ['required', 'string'],
            'message_type' => ['required', Rule::enum(MessageType::class)],
            'text' => ['required_if:message_type,text', 'nullable', 'string'],
            'idempotency_key' => ['required', 'string'],
            'payload' => ['nullable', 'array'],
        ];
    }
}
