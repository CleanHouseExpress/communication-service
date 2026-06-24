<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class InternalConversationCloseRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'tenant_id' => ['required', 'string', 'max:100'],
            'reason' => ['nullable', 'string', 'max:500'],
        ];
    }
}
