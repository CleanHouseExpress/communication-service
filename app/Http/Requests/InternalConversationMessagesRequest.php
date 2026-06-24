<?php

namespace App\Http\Requests;

use App\Enums\MessageDirection;
use App\Enums\MessageType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class InternalConversationMessagesRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'tenant_id' => ['required', 'string', 'max:100'],
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
            'direction' => ['nullable', Rule::enum(MessageDirection::class)],
            'message_type' => ['nullable', Rule::enum(MessageType::class)],
        ];
    }
}
