<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class InternalInboxSummaryRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'tenant_id' => ['required', 'string', 'max:100'],
            'assigned_external_user_id' => ['nullable', 'string', 'max:100'],
        ];
    }
}
