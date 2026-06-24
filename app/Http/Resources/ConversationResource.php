<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ConversationResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'tenant_id' => $this->tenant_id,
            'channel_id' => $this->channel_id,
            'contact_id' => $this->contact_id,
            'status' => $this->status,
            'last_message_at' => $this->last_message_at?->toIso8601String(),
            'contact' => $this->whenLoaded('contact', fn (): ?array => $this->contact ? [
                'id' => $this->contact->id,
                'name' => $this->contact->name,
                'phone' => $this->contact->phone,
                'external_id' => $this->contact->external_id,
            ] : null),
            'latest_message' => $this->whenLoaded('messages', function (): ?array {
                $message = $this->messages->first();

                return $message ? [
                    'id' => $message->id,
                    'direction' => $message->direction,
                    'message_type' => $message->message_type,
                    'text' => $message->text,
                    'status' => $message->status,
                    'occurred_at' => $message->occurred_at?->toIso8601String(),
                    'created_at' => $message->created_at?->toIso8601String(),
                ] : null;
            }),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
