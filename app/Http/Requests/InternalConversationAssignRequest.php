<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class InternalConversationAssignRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'tenant_id' => ['required', 'string', 'max:100'],
            'external_user_id' => ['required', 'string', 'max:100'],
            'external_user_name' => ['nullable', 'string', 'max:150'],
        ];
    }
}
