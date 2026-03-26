<?php

namespace App\Http\Controllers;

use App\Services\ManualKnowledgeService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Laravel\Ai\Messages\AssistantMessage;
use Laravel\Ai\Messages\UserMessage;
use Symfony\Component\HttpFoundation\StreamedResponse;

use function Laravel\Ai\agent;

class ChatController extends Controller
{
    public function stream(Request $request, ManualKnowledgeService $manualKnowledge): StreamedResponse|JsonResponse
    {
        $validated = $request->validate([
            'messages' => ['required', 'array', 'min:1'],
            'messages.*.role' => ['required', 'string', 'in:user,assistant'],
            'messages.*.content' => ['nullable'],
            'messages.*.parts' => ['nullable', 'array'],
        ]);

        $messages = collect($validated['messages'])
            ->map(function (array $message): array {
                return [
                    'role' => $message['role'],
                    'content' => $this->extractText($message),
                ];
            })
            ->filter(fn (array $message) => $message['content'] !== '')
            ->values();

        $lastUserIndex = $messages
            ->keys()
            ->reverse()
            ->first(fn (int $index) => $messages[$index]['role'] === 'user');

        if ($lastUserIndex === null) {
            return response()->json([
                'message' => 'A user message is required to generate a response.',
            ], 422);
        }

        if (! $manualKnowledge->ensureIndexed()) {
            return response()->json([
                'message' => 'Manual PDF not found or could not be indexed. Put a PDF in public/chatbot_data_docs.',
            ], 422);
        }

        $prompt = $messages[$lastUserIndex]['content'];
        $matchedChunks = $manualKnowledge->search($prompt, limit: 4);
        $manualContext = $manualKnowledge->contextFromChunks($matchedChunks);

        $conversationHistory = $messages
            ->slice(0, $lastUserIndex)
            ->map(function (array $message) {
                return $message['role'] === 'user'
                    ? new UserMessage($message['content'])
                    : new AssistantMessage($message['content']);
            })
            ->all();

        $groundedPrompt = <<<PROMPT
        User question:
        {$prompt}

        Relevant manual context (retrieved chunks):
        {$manualContext}

        Instructions:
        - Answer using the manual context above.
        - Prefer the highest score chunks when context conflicts.
        - If answer is not present in context, clearly say "Not found in manual".
        - Keep answer clear and concise.
        PROMPT;

        return agent(
            instructions: 'You are a manual-grounded support assistant. Do not invent facts beyond the provided manual context.',
            messages: $conversationHistory,
        )->stream($groundedPrompt);
    }

    private function extractText(array $message): string
    {
        if (is_string($message['content'] ?? null)) {
            return trim($message['content']);
        }

        $parts = collect($message['parts'] ?? []);

        if ($parts->isEmpty()) {
            return '';
        }

        return trim($parts
            ->map(fn ($part) => is_array($part) && ($part['type'] ?? null) === 'text'
                ? (string) ($part['text'] ?? '')
                : '')
            ->join(''));
    }
}
