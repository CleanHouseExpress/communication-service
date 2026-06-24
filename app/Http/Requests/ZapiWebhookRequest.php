<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class ZapiWebhookRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            '*' => ['nullable'],
        ];
    }

    public function after(): array
    {
        return [
            function (Validator $validator): void {
                if ($this->all() === []) {
                    $validator->errors()->add('payload', 'Webhook payload cannot be empty.');
                }

                if ($this->firstScalar(['phone', 'from', 'sender', 'participantPhone']) === null) {
                    $validator->errors()->add('phone', 'Webhook payload must include a sender identifier.');
                }
            },
        ];
    }

    private function firstScalar(array $keys): ?string
    {
        foreach ($keys as $key) {
            $value = data_get($this->all(), $key);

            if (is_scalar($value) && trim((string) $value) !== '') {
                return trim((string) $value);
            }
        }

        return null;
    }
}
