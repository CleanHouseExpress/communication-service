<?php

namespace App\Http\Controllers\Internal;

use App\Contracts\Messaging\MessageSenderInterface;
use App\DTO\Messaging\MessagePayload;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class WhatsAppMessageController extends Controller
{
    public function text(Request $request, MessageSenderInterface $sender): JsonResponse
    {
        $validated = $request->validate($this->rules('text'));

        $result = $sender->sendText(new MessagePayload(
            instanceName: $validated['instance_name'],
            number: $validated['number'],
            message: $validated['message'],
        ));

        return response()->json($result->toArray(), $result->success ? 202 : 502);
    }

    public function image(Request $request, MessageSenderInterface $sender): JsonResponse
    {
        $validated = $request->validate($this->rules('image'));
        $result = $sender->sendImage($this->mediaPayload($validated));

        return response()->json($result->toArray(), $result->success ? 202 : 502);
    }

    public function document(Request $request, MessageSenderInterface $sender): JsonResponse
    {
        $validated = $request->validate($this->rules('document'));
        $result = $sender->sendDocument($this->mediaPayload($validated));

        return response()->json($result->toArray(), $result->success ? 202 : 502);
    }

    public function audio(Request $request, MessageSenderInterface $sender): JsonResponse
    {
        $validated = $request->validate($this->rules('audio'));
        $result = $sender->sendAudio($this->mediaPayload($validated));

        return response()->json($result->toArray(), $result->success ? 202 : 502);
    }

    private function rules(string $type): array
    {
        $rules = [
            'instance_name' => ['required', 'string', 'max:128'],
            'number' => ['required', 'string', 'max:32'],
        ];

        if ($type === 'text') {
            $rules['message'] = ['required', 'string', 'max:4096'];

            return $rules;
        }

        $rules['media_url'] = ['required', 'url', 'max:2048'];
        $rules['caption'] = ['nullable', 'string', 'max:1024'];
        $rules['file_name'] = [
            Rule::requiredIf($type === 'document'),
            'nullable',
            'string',
            'max:255',
        ];

        return $rules;
    }

    private function mediaPayload(array $validated): MessagePayload
    {
        return new MessagePayload(
            instanceName: $validated['instance_name'],
            number: $validated['number'],
            mediaUrl: $validated['media_url'],
            caption: $validated['caption'] ?? null,
            fileName: $validated['file_name'] ?? null,
        );
    }
}
