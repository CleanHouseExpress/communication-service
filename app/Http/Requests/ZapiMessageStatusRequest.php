<?php

namespace App\Http\Requests;

use App\Enums\MessageStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ZapiMessageStatusRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'tenant_id' => ['nullable', 'string', 'max:100'],
            'provider_message_id' => ['nullable', 'required_without:external_message_id', 'string', 'max:255'],
            'external_message_id' => ['nullable', 'required_without:provider_message_id', 'string', 'max:255'],
            'status' => [
                'required',
                Rule::in([
                    MessageStatus::Sent->value,
                    MessageStatus::Delivered->value,
                    MessageStatus::Read->value,
                    MessageStatus::Failed->value,
                ]),
            ],
            'timestamp' => ['required', 'date'],
        ];
    }
}
