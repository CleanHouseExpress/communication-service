<?php

namespace App\Http\Resources;

use App\Support\Messages\SafeMessageMedia;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class MessageResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'tenant_id' => $this->tenant_id,
            'conversation_id' => $this->conversation_id,
            'contact_id' => $this->contact_id,
            'channel_id' => $this->channel_id,
            'provider' => $this->provider,
            'direction' => $this->direction,
            'message_type' => $this->message_type,
            'text' => $this->text,
            'media' => SafeMessageMedia::fromMessage($this->resource),
            'status' => $this->status,
            'occurred_at' => $this->occurred_at?->toIso8601String(),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
