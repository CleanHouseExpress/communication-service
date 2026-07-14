<?php

namespace App\Http\Controllers\Internal;

use App\Actions\Tenancy\ResolveTenantRuntimeConnectionAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\InternalConversationMessagesRequest;
use App\Http\Requests\InternalConversationMessageStatusRequest;
use App\Http\Resources\MessageResource;
use App\Http\Resources\MessageStatusResource;
use App\Models\CommunicationConversation;
use App\Models\CommunicationMessage;
use App\Queries\Inbox\ListConversationMessagesQuery;
use App\Queries\Inbox\ListConversationMessageStatusesQuery;
use App\Support\Messages\SafeMessageMedia;
use App\Support\Tenancy\CurrentTenantConnection;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Http;
use Symfony\Component\HttpFoundation\Response;

class InboxMessageController extends Controller
{
    public function index(
        string $conversationId,
        InternalConversationMessagesRequest $request,
        ListConversationMessagesQuery $query,
    ): AnonymousResourceCollection {
        return MessageResource::collection($query->handle($conversationId, $request->validated()));
    }

    public function status(
        string $conversationId,
        InternalConversationMessageStatusRequest $request,
        ListConversationMessageStatusesQuery $query,
    ): AnonymousResourceCollection {
        return MessageStatusResource::collection(
            $query->handle($conversationId, $request->validated('tenant_id')),
        );
    }

    public function media(
        string $conversationId,
        string $messageId,
        Request $request,
        ResolveTenantRuntimeConnectionAction $resolveTenantRuntimeConnection,
        CurrentTenantConnection $currentTenantConnection,
    ): Response {
        $validated = $request->validate([
            'tenant_id' => ['required', 'string', 'max:80'],
        ]);
        $tenantId = (string) $validated['tenant_id'];
        $hadTenantContext = $currentTenantConnection->connectionName() !== null;
        $resolveTenantRuntimeConnection->handle($tenantId);

        try {
            CommunicationConversation::query()
                ->where('tenant_id', $tenantId)
                ->where('id', $conversationId)
                ->firstOrFail();

            $message = CommunicationMessage::query()
                ->where('tenant_id', $tenantId)
                ->where('conversation_id', $conversationId)
                ->where('id', $messageId)
                ->firstOrFail();

            $payload = is_array($message->payload) ? $message->payload : [];
            $media = SafeMessageMedia::fromPayload($payload, $message->message_type, allowProviderUrls: true);

            if ($media === null) {
                abort(404, 'Media not available.');
            }

            $mimeType = $media['mime_type'] ?? 'application/octet-stream';
            $fileName = $media['file_name'] ?? "message-{$message->id}";

            if (! empty($media['base64'])) {
                $contents = base64_decode((string) $media['base64'], true);

                if ($contents === false) {
                    abort(404, 'Media not available.');
                }

                return $this->mediaResponse($contents, $mimeType, $fileName);
            }

            if (! empty($media['url']) && str_starts_with((string) $media['url'], 'data:')) {
                $contents = $this->contentsFromDataUrl((string) $media['url']);

                if ($contents === null) {
                    abort(404, 'Media not available.');
                }

                return $this->mediaResponse($contents, $mimeType, $fileName);
            }

            if (! empty($media['url'])) {
                $response = Http::timeout(20)
                    ->accept($mimeType)
                    ->withHeaders(['User-Agent' => 'Orchestra-Communication-Service/1.0'])
                    ->get((string) $media['url']);

                if ($response->failed()) {
                    abort(404, 'Media not available.');
                }

                return $this->mediaResponse($response->body(), $response->header('Content-Type') ?: $mimeType, $fileName);
            }

            abort(404, 'Media not available.');
        } finally {
            if (! $hadTenantContext) {
                $currentTenantConnection->clear();
            }
        }
    }

    private function mediaResponse(string $contents, string $mimeType, string $fileName): Response
    {
        return response($contents, 200, [
            'Content-Type' => $mimeType,
            'Content-Disposition' => 'inline; filename="'.addcslashes($fileName, '"\\').'"',
            'Cache-Control' => 'private, max-age=300',
        ]);
    }

    private function contentsFromDataUrl(string $url): ?string
    {
        if (preg_match('/^data:([^;]+);base64,(.+)$/', $url, $matches) !== 1) {
            return null;
        }

        $contents = base64_decode($matches[2], true);

        return $contents === false ? null : $contents;
    }
}
