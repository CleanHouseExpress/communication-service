<?php

namespace App\Http\Requests;

use App\Enums\CommunicationTenantStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class InternalTenantSyncRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'orchestra_tenant_id' => ['required', 'string', 'max:100'],
            'name' => ['nullable', 'string', 'max:150'],
            'slug' => ['nullable', 'string', 'max:150'],
            'status' => ['required', Rule::enum(CommunicationTenantStatus::class)],
            'timezone' => ['nullable', 'string', 'max:80'],
            'metadata' => ['nullable', 'array', 'max:100'],
        ];
    }
}
