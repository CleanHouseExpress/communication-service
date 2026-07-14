<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class InternalConversationStartRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'tenant_id' => ['required', 'string', 'max:100'],
            'contact_id' => ['nullable', 'uuid', 'required_without:contact'],
            'contact' => ['nullable', 'array', 'required_without:contact_id'],
            'contact.name' => ['required_with:contact', 'string', 'max:120'],
            'contact.phone' => ['required_with:contact', 'string', 'max:32'],
            'create_user' => ['nullable', 'boolean'],
            'assigned_external_user_id' => ['nullable', 'string', 'max:100'],
            'assigned_external_user_name' => ['nullable', 'string', 'max:120'],
            'provider' => ['nullable', 'string', 'max:50'],
            'channel_id' => ['nullable', 'uuid'],
        ];
    }
}
