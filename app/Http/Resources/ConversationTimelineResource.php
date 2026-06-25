<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ConversationTimelineResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'event_type' => $this->event_type,
            'actor_type' => $this->actor_type,
            'actor_name' => $this->actor_name,
            'description' => $this->description,
            'metadata' => $this->safeMetadata($this->metadata ?? []),
            'occurred_at' => $this->occurred_at?->toIso8601String(),
        ];
    }

    private function safeMetadata(array $metadata): array
    {
        $unsafeKeys = ['token', 'secret', 'authorization', 'password', 'payload', 'provider_response'];

        return collect($metadata)
            ->reject(fn (mixed $value, string|int $key): bool => in_array(strtolower((string) $key), $unsafeKeys, true))
            ->map(fn (mixed $value): mixed => is_array($value) ? $this->safeMetadata($value) : $value)
            ->all();
    }
}
