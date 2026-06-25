<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class InternalConversationMessageStatusRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'tenant_id' => ['required', 'string', 'max:100'],
        ];
    }
}
