<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class ProvisionWhatsappChannelRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'tenant_id' => ['nullable', 'string', 'max:128'],
            'name' => ['nullable', 'string', 'max:150'],
            'expected_phone_number' => ['nullable', 'string', 'max:40', 'regex:/^[0-9+()\-\s]+$/'],
            'default_department_id' => ['nullable', 'string', 'max:128'],
            'default_assignee_id' => ['nullable', 'string', 'max:128'],
        ];
    }

    public function payload(): array
    {
        return [
            ...$this->validated(),
            'tenant_id' => $this->validated('tenant_id')
                ?? $this->header('X-Tenant-Id')
                ?? $this->header('X-Orchestra-Tenant-Id'),
        ];
    }
}
