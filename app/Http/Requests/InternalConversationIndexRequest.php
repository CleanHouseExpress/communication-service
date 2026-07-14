<?php

namespace App\Http\Requests;

use App\Enums\ConversationStatus;
use App\Enums\ConversationHandoffStatus;
use App\Enums\ConversationServiceMode;
use App\Enums\MessageDirection;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class InternalConversationIndexRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'tenant_id' => ['required', 'string', 'max:100'],
            'status' => ['nullable', Rule::enum(ConversationStatus::class)],
            'statuses' => ['nullable', 'array'],
            'statuses.*' => ['string', Rule::enum(ConversationStatus::class)],
            'service_mode' => ['nullable', Rule::enum(ConversationServiceMode::class)],
            'handoff_status' => ['nullable', Rule::enum(ConversationHandoffStatus::class)],
            'assignment_status' => ['nullable', Rule::in(['unassigned', 'assigned'])],
            'assigned_external_user_id' => ['nullable', 'string', 'max:100'],
            'handoff' => ['nullable', Rule::in(['requested', 'none'])],
            'has_handoff_requested' => ['nullable', Rule::in(['true', 'false', '1', '0', 1, 0, true, false])],
            'closed' => ['nullable', Rule::in(['true', 'false', '1', '0', 1, 0, true, false])],
            'last_message_from' => ['nullable', Rule::enum(MessageDirection::class)],
            'updated_since' => ['nullable', 'date'],
            'sort' => ['nullable', Rule::in(['last_message_at', 'created_at', 'updated_at'])],
            'direction' => ['nullable', Rule::in(['asc', 'desc'])],
            'contact_id' => ['nullable', 'uuid'],
            'channel_id' => ['nullable', 'uuid'],
            'search' => ['nullable', 'string', 'max:100'],
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ];
    }
}
