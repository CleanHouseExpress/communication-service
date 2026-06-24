<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class InternalAgentRunRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'message_id' => ['required', 'uuid', 'exists:communication_messages,id'],
        ];
    }
}
