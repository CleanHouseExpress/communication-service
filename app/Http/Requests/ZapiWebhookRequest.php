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
            'messageId' => ['nullable', 'string', 'max:255'],
            'message_id' => ['nullable', 'string', 'max:255'],
            'id' => ['nullable', 'string', 'max:255'],
            'eventId' => ['nullable', 'string', 'max:255'],
            'event_id' => ['nullable', 'string', 'max:255'],
            'webhookId' => ['nullable', 'string', 'max:255'],
            'webhook_id' => ['nullable', 'string', 'max:255'],
            'phone' => ['nullable', 'string', 'max:64'],
            'from' => ['nullable', 'string', 'max:64'],
            'sender' => ['nullable', 'string', 'max:64'],
            'participantPhone' => ['nullable', 'string', 'max:64'],
            'senderName' => ['nullable', 'string', 'max:255'],
            'sender_name' => ['nullable', 'string', 'max:255'],
            'name' => ['nullable', 'string', 'max:255'],
            'text' => ['nullable'],
            'text.message' => ['nullable', 'string', 'max:4096'],
            'message' => ['nullable', 'string', 'max:4096'],
            'body' => ['nullable', 'string', 'max:4096'],
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

                if (strlen((string) json_encode($this->all())) > 32768) {
                    $validator->errors()->add('payload', 'Webhook payload is too large.');
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
