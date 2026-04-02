<?php

namespace App\Http\Controllers;

use App\Models\ChatResponseMemory;
use App\Services\ManualKnowledgeService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Symfony\Component\HttpFoundation\StreamedResponse;

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
                'message' => 'Manual document not found or could not be indexed. Put a DOCX/TXT/MD file in public/chatbot_data_docs.',
            ], 422);
        }

        $prompt = $messages[$lastUserIndex]['content'];
        $matchedChunks = $manualKnowledge->search($prompt, limit: 4);
        $helpfulMemories = $this->searchHelpfulMemories($prompt, limit: 3);
        $tonePreset = $this->detectTonePreset($prompt);
        $styleVariant = random_int(0, 2);
        $answerPayload = $this->buildAnswer($prompt, $matchedChunks, $helpfulMemories, $styleVariant, $tonePreset);
        $relatedQuestions = $this->buildSuggestedQuestions($prompt, $matchedChunks, limit: 5);

        $memory = ChatResponseMemory::query()->create([
            'question' => $prompt,
            'normalized_question' => $this->normalizeText($prompt),
            'answer' => $answerPayload['text'],
            'helpful' => null,
            'metadata' => [
                'source' => $answerPayload['source'],
                'chunk_orders' => $answerPayload['chunk_orders'],
                'history_ids' => $answerPayload['history_ids'],
                'related_questions' => $relatedQuestions,
                'tone' => $tonePreset,
            ],
        ]);

        return $this->streamLocalAnswer(
            responseId: $memory->id,
            answer: $answerPayload['text'],
            source: $answerPayload['source'],
            relatedQuestions: $relatedQuestions,
            tonePreset: $tonePreset,
        );
    }

    public function suggestions(Request $request, ManualKnowledgeService $manualKnowledge): JsonResponse
    {
        $validated = $request->validate([
            'q' => ['nullable', 'string', 'max:280'],
        ]);

        if (! $manualKnowledge->ensureIndexed()) {
            return response()->json([
                'message' => 'Manual document not found or could not be indexed.',
                'questions' => [],
            ], 422);
        }

        $query = trim((string) ($validated['q'] ?? ''));
        $matchedChunks = $query !== ''
            ? $manualKnowledge->search($query, limit: 6)
            : [];

        return response()->json([
            'questions' => $this->buildSuggestedQuestions($query !== '' ? $query : null, $matchedChunks, limit: 8),
        ]);
    }

    public function feedback(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'response_id' => ['required', 'integer', 'exists:chat_response_memories,id'],
            'helpful' => ['required', 'boolean'],
        ]);

        $memory = ChatResponseMemory::query()->findOrFail($validated['response_id']);

        if ($validated['helpful'] && $this->hasCorruptedArtifacts($memory->answer)) {
            $memory->update(['helpful' => false]);

            return response()->json([
                'message' => 'Corrupted/unclear text detect hua, isliye is response ko historical memory me include nahi kiya gaya.',
            ]);
        }

        $memory->update(['helpful' => $validated['helpful']]);

        return response()->json([
            'message' => 'Feedback saved.',
        ]);
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

    /**
     * @return array<int, array{id:int, question:string, answer:string, score:int}>
     */
    private function searchHelpfulMemories(string $query, int $limit = 3): array
    {
        $terms = $this->queryTerms($query);
        $queryIntent = $this->detectIntent($query);
        $queryAction = $this->detectActionPreference($query);

        $builder = ChatResponseMemory::query()
            ->where('helpful', true)
            ->select(['id', 'question', 'normalized_question', 'answer'])
            ->latest();

        if ($terms->isNotEmpty()) {
            $builder->where(function ($or) use ($terms) {
                foreach ($terms as $term) {
                    $or->orWhere('normalized_question', 'like', '%'.$term.'%');
                    $or->orWhere('answer', 'like', '%'.$term.'%');
                }
            });
        }

        return $builder
            ->limit(50)
            ->get()
            ->map(function (ChatResponseMemory $memory) use ($terms) {
                $score = $terms->sum(
                    fn (string $term) => substr_count($memory->normalized_question, $term)
                        + substr_count($this->normalizeText($memory->answer), $term)
                );

                return [
                    'id' => $memory->id,
                    'question' => $memory->question,
                    'answer' => $memory->answer,
                    'score' => $score,
                    'intent' => $this->detectIntent($memory->question.' '.$memory->answer),
                ];
            })
            ->filter(function (array $memory) use ($queryIntent, $queryAction) {
                if ($memory['score'] <= 0) {
                    return false;
                }

                if ($this->isTemplateLikeAnswer($memory['answer'])) {
                    return false;
                }

                if ($this->hasCorruptedArtifacts($memory['answer'])) {
                    return false;
                }

                if ($queryIntent === 'general') {
                    if ($queryAction !== 'none' && ! $this->snippetMatchesAction($memory['question'].' '.$memory['answer'], $queryAction)) {
                        return false;
                    }

                    return $memory['score'] >= 2;
                }

                if ($memory['intent'] !== $queryIntent) {
                    return false;
                }

                if ($queryAction !== 'none' && ! $this->snippetMatchesAction($memory['question'].' '.$memory['answer'], $queryAction)) {
                    return false;
                }

                return true;
            })
            ->sortByDesc('score')
            ->take($limit)
            ->values()
            ->map(fn (array $memory) => [
                'id' => $memory['id'],
                'question' => $memory['question'],
                'answer' => $memory['answer'],
                'score' => $memory['score'],
            ])
            ->all();
    }

    /**
     * @param  array<int, array{id:int, chunk_order:int, content:string, score:int}>  $manualChunks
     * @param  array<int, array{id:int, question:string, answer:string, score:int}>  $historyRows
     * @return array{text:string,source:string,chunk_orders:array<int,int>,history_ids:array<int,int>}
     */
    private function buildAnswer(
        string $query,
        array $manualChunks,
        array $historyRows,
        int $styleVariant = 0,
        string $tonePreset = 'friendly'
    ): array {
        $terms = $this->queryTerms($query);

        $manualRelevant = collect($manualChunks)
            ->filter(fn (array $chunk) => $chunk['score'] > 0)
            ->values();

        $historyRelevant = collect($historyRows)
            ->filter(fn (array $row) => $row['score'] > 0)
            ->values();

        if ($manualRelevant->isEmpty() && $historyRelevant->isEmpty()) {
            return [
                'text' => 'Mujhe is question ka clear answer manual me nahi mila. Kripya question thoda aur specific likhiye.',
                'source' => 'none',
                'chunk_orders' => [],
                'history_ids' => [],
            ];
        }

        $chunkOrders = $manualRelevant->take(2)->pluck('chunk_order')->all();
        $historyIds = $historyRelevant->take(2)->pluck('id')->all();

        $manualCandidates = $manualRelevant
            ->take(3)
            ->map(function (array $chunk) use ($terms) {
                return [
                    'text' => $this->sanitizeSnippet(
                        $this->bestSnippet($chunk['content'], $terms)
                    ),
                    'score' => $chunk['score'],
                ];
            });

        $historyCandidates = $historyRelevant
            ->take(3)
            ->map(function (array $row) use ($terms) {
                return [
                    'text' => $this->sanitizeSnippet(
                        $this->bestSnippet($this->sanitizeHistoryText($row['answer']), $terms)
                    ),
                    // Helpful history gets a small boost for related questions.
                    'score' => $row['score'] + 2,
                ];
            });

        $bestSnippets = $historyCandidates
            ->merge($manualCandidates)
            ->filter(fn (array $item) => $item['text'] !== '' && ! $this->isNoisySnippet($item['text']))
            ->sortByDesc('score')
            ->unique(fn (array $item) => $this->normalizeText($item['text']))
            ->take(3)
            ->pluck('text')
            ->values();

        if ($bestSnippets->isEmpty()) {
            return [
                'text' => 'Mujhe relevant answer milne me issue aa raha hai. Kripya short aur clear question poochiye.',
                'source' => 'none',
                'chunk_orders' => $chunkOrders,
                'history_ids' => $historyIds,
            ];
        }

        $source = match (true) {
            $manualRelevant->isNotEmpty() && $historyRelevant->isNotEmpty() => 'hybrid',
            $manualRelevant->isNotEmpty() => 'manual',
            default => 'history',
        };

        return [
            'text' => $this->formatForUser($query, $bestSnippets->all(), $styleVariant, $tonePreset),
            'source' => $source,
            'chunk_orders' => $chunkOrders,
            'history_ids' => $historyIds,
        ];
    }

    private function bestSnippet(string $text, Collection $terms, int $maxChars = 280): string
    {
        $sentences = $this->splitSentences($text);

        if ($sentences === []) {
            return mb_substr(trim($text), 0, $maxChars);
        }

        $best = collect($sentences)
            ->map(function (string $sentence) use ($terms) {
                $normalized = $this->normalizeText($sentence);
                $score = $terms->sum(fn (string $term) => substr_count($normalized, $term));

                return [
                    'text' => trim($sentence),
                    'score' => $score,
                ];
            })
            ->sortByDesc('score')
            ->first();

        if (! $best || trim($best['text']) === '') {
            return mb_substr(trim($text), 0, $maxChars);
        }

        return mb_substr($best['text'], 0, $maxChars);
    }

    /**
     * @return string[]
     */
    private function splitSentences(string $text): array
    {
        $sentences = preg_split('/(?<=[\.\!\?।])\s+/u', $text) ?: [];
        $sentences = array_values(array_filter(array_map('trim', $sentences), fn (string $line) => $line !== ''));

        if ($sentences !== []) {
            return $sentences;
        }

        $lines = preg_split('/\s{2,}/u', $text) ?: [];

        return array_values(array_filter(array_map('trim', $lines), fn (string $line) => $line !== ''));
    }

    /**
     * @return Collection<int, string>
     */
    private function queryTerms(string $query): Collection
    {
        $normalized = $this->normalizeText($query);
        $stopwords = [
            'how', 'may', 'the', 'this', 'that', 'with', 'from', 'for', 'what', 'where',
            'kaise', 'kya', 'hai', 'hoga', 'kar', 'kare', 'karen', 'karo', 'portal', 'please',
            'mujhe', 'mera', 'meri', 'mere', 'mein', 'main', 'iska', 'iske', 'apna', 'list', 'dekhen',
        ];

        $terms = collect(preg_split('/\s+/', $normalized))
            ->filter(fn (?string $term) => $term && mb_strlen($term) >= 3)
            ->reject(fn (string $term) => in_array($term, $stopwords, true))
            ->unique()
            ->values();

        $expanded = [];

        if (str_contains($normalized, 'login') || str_contains($normalized, 'signin') || str_contains($normalized, 'password')) {
            $expanded = array_merge($expanded, ['login', 'otp', 'password', 'लॉगिन', 'पासवर्ड', 'लिंक']);
        }

        if (str_contains($normalized, 'otp') || str_contains($normalized, 'verify')) {
            $expanded = array_merge($expanded, ['otp', 'verify', 'verification', 'सत्यापन', 'ओटीपी']);
        }

        if (str_contains($normalized, 'post') || str_contains($normalized, 'vacancy')) {
            $expanded = array_merge($expanded, ['post', 'vacancy', 'पद', 'रिक्ति', 'पोस्ट']);
        }

        if (str_contains($normalized, 'advertisement') || str_contains($normalized, 'advertisment') || str_contains($normalized, 'publish')) {
            $expanded = array_merge($expanded, ['advertisement', 'विज्ञापन', 'अधिसूचना', 'publish', 'प्रकाशित']);
        }

        if (str_contains($normalized, 'gram') || str_contains($normalized, 'village') || str_contains($normalized, 'ward')) {
            $expanded = array_merge($expanded, ['gram', 'village', 'ward', 'ग्राम', 'वार्ड', 'ब्लॉक', 'क्षेत्र']);
        }

        if (str_contains($normalized, 'panchayat')) {
            $expanded = array_merge($expanded, ['panchayat', 'पंचायत', 'ग्राम', 'वार्ड']);
        }

        if (str_contains($normalized, 'master') || str_contains($normalized, 'update') || str_contains($normalized, 'create') || str_contains($normalized, 'add')) {
            $expanded = array_merge($expanded, ['master', 'update', 'create', 'add', 'मास्टर', 'संशोधन', 'जोड़ें']);
        }

        if (str_contains($normalized, 'application') || str_contains($normalized, 'applicant') || str_contains($normalized, 'aavedan')) {
            $expanded = array_merge($expanded, ['application', 'applicant', 'आवेदन', 'आवेदक', 'सूची']);
        }

        if (str_contains($normalized, 'merit') || str_contains($normalized, 'rank') || str_contains($normalized, 'ranking')) {
            $expanded = array_merge($expanded, ['merit', 'rank', 'ranking', 'मेरिट', 'रैंक', 'अंक']);
        }

        if (str_contains($normalized, 'download') || str_contains($normalized, 'report')) {
            $expanded = array_merge($expanded, ['download', 'report', 'डाउनलोड', 'रिपोर्ट', 'excel']);
        }

        return $terms
            ->merge($expanded)
            ->map(fn (string $term) => trim($term))
            ->filter(fn (string $term) => $term !== '')
            ->unique()
            ->values();
    }

    private function normalizeText(string $text): string
    {
        $normalized = mb_strtolower($text);
        $normalized = preg_replace('/[^\p{L}\p{M}\p{N}\s]/u', ' ', $normalized) ?? '';

        return trim((string) preg_replace('/\s+/u', ' ', $normalized));
    }

    private function sanitizeHistoryText(string $text): string
    {
        $text = str_replace(["From manual:", "From helpful history:"], '', $text);
        $text = preg_replace('/^\s*-\s*/mu', '', $text) ?? $text;

        return trim((string) preg_replace('/\n{2,}/', "\n", $text));
    }

    private function sanitizeSnippet(string $text): string
    {
        $text = preg_replace('/[A-Za-z]:\\\\[^\s]+/u', ' ', $text) ?? $text;
        $text = preg_replace('/\S+\.(docx|pdf)/iu', ' ', $text) ?? $text;
        $text = preg_replace('/\(\s*चित्र[^)]*\)/u', ' ', $text) ?? $text;
        $text = preg_replace('/\(\s*figure[^)]*\)/iu', ' ', $text) ?? $text;
        $text = preg_replace('/\bचित्र\s*\d+\b/u', ' ', $text) ?? $text;
        $text = str_replace(['•', ' - ', '- '], ' ', $text);
        $text = str_replace(['e bharti', 'E Bharti'], 'e-bharti', $text);

        $lower = mb_strtolower($text);
        if (str_contains($lower, 'user manual')) {
            $loginPos = mb_stripos($lower, 'login');
            $hindiLoginPos = mb_stripos($text, 'लॉगिन');

            if ($loginPos !== false) {
                $text = mb_substr($text, $loginPos);
            } elseif ($hindiLoginPos !== false) {
                $text = mb_substr($text, $hindiLoginPos);
            }
        }

        $text = trim((string) preg_replace('/\s+/u', ' ', $text));
        $text = preg_replace('/\s+([।,:;!?])/u', '$1', $text) ?? $text;

        return $this->fixCommonHindiOcrWords($text);
    }

    private function isNoisySnippet(string $text): bool
    {
        if (mb_strlen($text) < 25) {
            return true;
        }

        if (str_contains($text, 'C:\\Users\\')) {
            return true;
        }

        $lettersOnly = preg_replace('/[^\p{L}]+/u', '', $text) ?? '';

        return mb_strlen($lettersOnly) < 15;
    }

    /**
     * @param  string[]  $snippets
     */
    private function formatForUser(
        string $query,
        array $snippets,
        int $styleVariant = 0,
        string $tonePreset = 'friendly'
    ): string {
        $tonePreset = in_array($tonePreset, ['friendly', 'formal', 'concise', 'detailed'], true)
            ? $tonePreset
            : 'friendly';

        $queryIntent = $this->detectIntent($query);
        $queryAction = $this->detectActionPreference($query);
        $snippets = array_values(array_filter(array_map('trim', $snippets), fn (string $line) => $line !== ''));
        $snippets = $this->dedupeSnippets($snippets);

        if ($snippets === []) {
            return 'Mujhe abhi clear answer nahi mila. Kripya thoda specific question poochiye.';
        }

        if ($queryIntent === 'login') {
            $loginSnippets = collect($snippets)
                ->filter(fn (string $line) => $this->snippetMatchesIntent($line, 'login'))
                ->unique()
                ->values();

            if ($loginSnippets->isEmpty()) {
                return 'Mujhe login ka exact step manual me clear form me nahi mila.';
            }

            if ($loginSnippets->count() < 2) {
                $loginSnippets = $loginSnippets->merge(
                    collect($snippets)->reject(fn (string $line) => $this->snippetMatchesIntent($line, 'login'))
                )->unique()->values();
            }

            return $this->toneIntro($tonePreset, 'login', $styleVariant)."\n".implode("\n", $loginSnippets
                ->take($this->resolveSnippetLimit($tonePreset, 'login', 2))
                ->map(fn (string $line, int $idx) => ($idx + 1).'. '.$line)
                ->all());
        }

        if ($queryIntent === 'otp') {
            $otpSnippets = collect($snippets)
                ->filter(fn (string $line) => $this->snippetMatchesIntent($line, 'otp'))
                ->unique()
                ->values();

            if ($otpSnippets->isEmpty()) {
                return 'Mujhe OTP verification ka exact step manual me clear form me nahi mila.';
            }

            return $this->toneIntro($tonePreset, 'otp', $styleVariant)."\n".implode("\n", $otpSnippets
                ->take($this->resolveSnippetLimit($tonePreset, 'otp', 3))
                ->map(fn (string $line, int $idx) => ($idx + 1).'. '.$line)
                ->all());
        }

        if ($queryIntent === 'merit') {
            $meritSnippets = collect($snippets)
                ->filter(fn (string $line) => $this->snippetMatchesIntent($line, 'merit'))
                ->unique()
                ->values();

            if ($meritSnippets->isEmpty()) {
                return 'Mujhe merit list ka exact step manual me clear form me nahi mila.';
            }

            return $this->toneIntro($tonePreset, 'merit', $styleVariant)."\n".implode("\n", $meritSnippets
                ->take($this->resolveSnippetLimit($tonePreset, 'merit', 3))
                ->map(fn (string $line, int $idx) => ($idx + 1).'. '.$line)
                ->all());
        }

        if ($queryIntent === 'complaint') {
            $complaintSnippets = collect($snippets)
                ->filter(fn (string $line) => $this->snippetMatchesIntent($line, 'complaint'))
                ->unique()
                ->values();

            if ($complaintSnippets->isEmpty()) {
                return 'Mujhe complaint/sahayata ka exact step manual me clear form me nahi mila.';
            }

            return $this->toneIntro($tonePreset, 'complaint', $styleVariant)."\n".implode("\n", $complaintSnippets
                ->take($this->resolveSnippetLimit($tonePreset, 'complaint', 3))
                ->map(fn (string $line, int $idx) => ($idx + 1).'. '.$line)
                ->all());
        }

        if ($queryIntent !== 'general') {
            $intentMatched = collect($snippets)
                ->filter(fn (string $line) => $this->snippetMatchesIntent($line, $queryIntent))
                ->values();

            if ($intentMatched->isNotEmpty()) {
                $snippets = $intentMatched->all();
            } elseif (in_array($queryIntent, ['contact', 'download', 'complaint', 'merit', 'advertisement', 'post', 'gram', 'panchayat', 'master'], true)) {
                return 'Mujhe is question ka exact answer manual me clear form me nahi mila.';
            }
        }

        if (
            $queryAction !== 'none'
            && in_array($queryIntent, ['advertisement', 'post', 'gram', 'panchayat', 'master', 'application'], true)
        ) {
            $actionMatched = collect($snippets)
                ->filter(fn (string $line) => $this->snippetMatchesAction($line, $queryAction))
                ->values();

            if ($actionMatched->isNotEmpty()) {
                $snippets = $actionMatched->all();
            } else {
                return "Mujhe {$queryAction} ka exact step manual me clear form me nahi mila.";
            }
        }

        if (! in_array($queryIntent, ['login', 'otp'], true)) {
            $withoutLoginNoise = array_values(array_filter($snippets, fn (string $line) => ! $this->isLoginSnippet($line)));
            if ($withoutLoginNoise !== []) {
                $snippets = $withoutLoginNoise;
            }
        }

        if (count($snippets) === 1) {
            return $this->singleSnippetByTone($tonePreset, $snippets[0]);
        }

        return $this->toneIntro($tonePreset, 'general', $styleVariant)."\n".implode(
            "\n",
            collect($snippets)
                ->take($this->resolveSnippetLimit($tonePreset, 'general', 3))
                ->map(fn (string $line, int $idx) => ($idx + 1).'. '.$line)
                ->all()
        );
    }

    private function detectTonePreset(string $query): string
    {
        $normalized = $this->normalizeText($query);

        if (str_contains($normalized, 'please')
            || str_contains($normalized, 'kindly')
            || str_contains($normalized, 'could you')
            || str_contains($normalized, 'would you')
            || str_contains($normalized, 'kripya')
            || str_contains($normalized, 'कृपया')) {
            return 'formal';
        }

        if (str_contains($normalized, 'detail')
            || str_contains($normalized, 'detailed')
            || str_contains($normalized, 'step by step')
            || str_contains($normalized, 'explain')
            || str_contains($normalized, 'samjha')
            || str_contains($normalized, 'समझा')
            || str_contains($normalized, 'पूरा')
            || str_contains($normalized, 'complete')) {
            return 'detailed';
        }

        if (str_contains($normalized, 'jaldi')
            || str_contains($normalized, 'short')
            || str_contains($normalized, 'brief')
            || str_contains($normalized, 'quick')
            || str_contains($normalized, 'seedha')
            || str_contains($normalized, 'fatafat')
            || str_contains($normalized, 'sirf')) {
            return 'concise';
        }

        return 'friendly';
    }

    private function resolveSnippetLimit(string $tonePreset, string $intent, int $default): int
    {
        if ($tonePreset === 'concise') {
            return 1;
        }

        if ($tonePreset === 'detailed') {
            $upper = in_array($intent, ['login', 'otp'], true) ? 3 : 4;

            return min($upper, $default + 1);
        }

        return $default;
    }

    private function toneIntro(string $tonePreset, string $intent, int $styleVariant): string
    {
        $intros = [
            'friendly' => [
                'login' => ['Login ke liye ye simple steps follow karein:', 'Portal login process is tarah complete karein:', 'Login karne ka seedha process:'],
                'otp' => ['OTP verify karne ke steps:', 'OTP validation process:', 'OTP complete karne ka tareeka:'],
                'merit' => ['Merit list dekhne ke liye:', 'Merit/ranking section use karne ka process:', 'Merit list check karne ke practical steps:'],
                'complaint' => ['Complaint bhejne ke liye:', 'Support/suggestion submit karne ka process:', 'Issue report karne ke steps:'],
                'general' => ['Aapke question ke liye relevant points:', 'Is topic par manual se important details:', 'Ye steps/points aapke kaam aayenge:'],
            ],
            'formal' => [
                'login' => ['Kripya login ke liye nimn steps follow karein:', 'Portal login prakriya nimn prakar hai:', 'Kripya nimnalikhit login process dekhein:'],
                'otp' => ['Kripya OTP satyapan ke steps dekhein:', 'OTP verification prakriya nimn hai:', 'Nimn anusaar OTP complete karein:'],
                'merit' => ['Kripya merit list dekhne ke steps dekhein:', 'Merit/ranking dekhne ki prakriya nimn hai:', 'Merit section ke liye nimn bindu upyogi hain:'],
                'complaint' => ['Kripya complaint darj karne ke steps follow karein:', 'Support/suggestion submit karne ki prakriya nimn hai:', 'Issue report karne ke liye nimn steps dekhein:'],
                'general' => ['Kripya nimnalikhit points dekhein:', 'Aapke prashn ke sambandh me relevant bindu:', 'Manual ke anusaar upyogi jaankari:'],
            ],
            'concise' => [
                'login' => ['Login ka short process:', 'Login ke direct steps:', 'Login quick steps:'],
                'otp' => ['OTP ke short steps:', 'OTP quick process:', 'OTP direct steps:'],
                'merit' => ['Merit list quick steps:', 'Merit ke direct points:', 'Merit list short process:'],
                'complaint' => ['Complaint ke quick steps:', 'Issue report short process:', 'Support request direct steps:'],
                'general' => ['Direct answer:', 'Short points:', 'Quick summary:'],
            ],
            'detailed' => [
                'login' => ['Login ko step-by-step samjhein:', 'Login process detail me:', 'Aasan bhaasha me login flow:'],
                'otp' => ['OTP process step-by-step:', 'OTP verification detail me:', 'Aasan bhaasha me OTP flow:'],
                'merit' => ['Merit list process detail me:', 'Merit/ranking ko step-by-step samjhein:', 'Merit section ka detailed flow:'],
                'complaint' => ['Complaint process detail me:', 'Issue report step-by-step:', 'Support/suggestion flow ko detail me samjhein:'],
                'general' => ['Detail explanation:', 'Step-by-step relevant points:', 'Aasan aur detail mein jawab:'],
            ],
        ];

        $tone = $intros[$tonePreset] ?? $intros['friendly'];
        $options = $tone[$intent] ?? $tone['general'];

        return $options[$styleVariant % max(1, count($options))];
    }

    private function singleSnippetByTone(string $tonePreset, string $snippet): string
    {
        return match ($tonePreset) {
            'formal' => 'Kripya dhyan dein: '.$snippet,
            'detailed' => 'Seedha point: '.$snippet,
            default => $snippet,
        };
    }

    /**
     * @param  string[]  $snippets
     * @return string[]
     */
    private function dedupeSnippets(array $snippets): array
    {
        $deduped = [];

        foreach ($snippets as $snippet) {
            $normalized = $this->normalizeText($snippet);
            if ($normalized === '') {
                continue;
            }

            $alreadyCovered = collect($deduped)->contains(function (string $existing) use ($normalized) {
                $existingNormalized = $this->normalizeText($existing);

                return $existingNormalized === $normalized
                    || str_contains($existingNormalized, $normalized)
                    || str_contains($normalized, $existingNormalized);
            });

            if (! $alreadyCovered) {
                $deduped[] = $snippet;
            }
        }

        return $deduped;
    }

    /**
     * @param  array<int, array{id:int, chunk_order:int, content:string, score:int}>  $matchedChunks
     * @return string[]
     */
    private function buildSuggestedQuestions(?string $query, array $matchedChunks, int $limit = 6): array
    {
        $bank = $this->questionBank();
        $query = $query !== null ? trim($query) : null;
        $topics = $query !== null && $query !== '' ? $this->detectSuggestionTopics($query) : [];

        $dynamicPool = collect($matchedChunks)
            ->flatMap(function (array $chunk) {
                $metadata = is_array($chunk['metadata'] ?? null) ? $chunk['metadata'] : [];

                return collect($metadata['candidate_questions'] ?? []);
            })
            ->map(fn ($question) => trim((string) $question))
            ->filter(fn (string $question) => $question !== '')
            ->unique()
            ->values();

        if ($topics === []) {
            $topics = ['general'];

            foreach (collect($matchedChunks)->take(3) as $chunk) {
                if (! isset($chunk['content'])) {
                    continue;
                }

                $topics = array_merge($topics, $this->detectSuggestionTopics((string) $chunk['content']));
            }

            foreach ($dynamicPool->take(6) as $question) {
                $topics = array_merge($topics, $this->detectSuggestionTopics((string) $question));
            }
        }

        $topics = array_values(array_unique($topics));
        if ($topics === []) {
            $topics = ['general'];
        }

        $primaryPool = [];
        foreach ($topics as $topic) {
            $primaryPool = array_merge($primaryPool, $bank[$topic] ?? []);
        }

        $primaryPool = array_values(array_unique(array_filter(array_map('trim', $primaryPool))));
        if ($primaryPool === [] && $dynamicPool->isEmpty()) {
            return [];
        }

        $seedSource = ($query ?? 'quick')."|".now()->format('YmdHi');
        $seed = abs((int) crc32($seedSource));
        if ($query !== null && $query !== '' && $dynamicPool->isNotEmpty()) {
            $queryTerms = $this->queryTerms($query);
            $dynamicPool = $dynamicPool
                ->map(function (string $question) use ($queryTerms) {
                    $score = $queryTerms->sum(fn (string $term) => substr_count($this->normalizeText($question), $term));

                    return [
                        'question' => $question,
                        'score' => $score,
                    ];
                })
                ->sortByDesc('score')
                ->pluck('question')
                ->values();
        } else {
            $dynamicPool = collect($this->seededShuffle($dynamicPool->all(), $seed + 41));
        }

        $primaryPool = $this->seededShuffle($primaryPool, $seed);

        $secondaryPool = array_values(array_unique(array_filter(array_map('trim', $bank['general'] ?? []))));
        $secondaryPool = $this->seededShuffle($secondaryPool, $seed + 97);

        $pool = array_values(array_unique(array_merge($dynamicPool->all(), $primaryPool, $secondaryPool)));

        return array_slice($pool, 0, $limit);
    }

    /**
     * @return array<string, array<int, string>>
     */
    private function questionBank(): array
    {
        return [
            'general' => [
                'Login kaise kare?',
                'Advertisement kaise create kare?',
                'Post kaise create kare?',
                'Post kaise update kare?',
                'Gram ka chayan kaise kare?',
                'Gram Panchayat master add/update kaise kare?',
                'Aavedan ki list kaise dekhen?',
                'Merit list kaise nikale?',
                'OTP verify kaise kare?',
                'Report download kaise kare?',
            ],
            'login' => [
                'Portal me first time login kaise kare?',
                'Password reset ka process kya hai?',
                'OTP na aaye to kya kare?',
                'Login ke baad dashboard kahan milega?',
            ],
            'otp' => [
                'OTP verify kaise kare?',
                'OTP expire ho jaye to kya kare?',
                'Mobile number galat ho to update kaise kare?',
            ],
            'advertisement' => [
                'Advertisement kaise create kare?',
                'Advertisement publish kaise kare?',
                'Advertisement edit/update kaise kare?',
            ],
            'post' => [
                'Post kaise create kare?',
                'Post kaise update kare?',
                'Vacancy details kaise set kare?',
            ],
            'gram' => [
                'Gram ka chayan kaise kare?',
                'Area type (Rural/Urban) kaise choose kare?',
                'District-Block-Village mapping kaise kare?',
            ],
            'panchayat' => [
                'Gram Panchayat master add kaise kare?',
                'Gram Panchayat master update kaise kare?',
                'Ward/Panchayat mapping kaise update kare?',
            ],
            'master' => [
                'Master data add kaise kare?',
                'Master data update kaise kare?',
                'Duplicate entry ko kaise handle kare?',
            ],
            'application' => [
                'Aavedan ki list kaise dekhen?',
                'Search aur filter ka use kaise kare?',
                'Aavedan details kaise open kare?',
            ],
            'merit' => [
                'Merit list kaise banegi?',
                'Ranking kis basis par hoti hai?',
                'Merit list download kaise kare?',
            ],
            'support' => [
                'Complaint/suggestion kaise bhejein?',
                'Support request ka status kaise check kare?',
            ],
        ];
    }

    /**
     * @return string[]
     */
    private function detectSuggestionTopics(string $text): array
    {
        $normalized = $this->normalizeText($text);
        $topicMap = [
            'login' => ['login', 'log in', 'signin', 'लॉगिन', 'पासवर्ड'],
            'otp' => ['otp', 'verify', 'verification', 'सत्यापन'],
            'advertisement' => ['advertisement', 'advertisment', 'विज्ञापन', 'सूचना'],
            'post' => ['post', 'vacancy', 'पद', 'रिक्ति'],
            'gram' => ['ग्राम', 'village', 'ward', 'ब्लॉक', 'क्षेत्र'],
            'panchayat' => ['पंचायत', 'panchayat'],
            'master' => ['master', 'create', 'update', 'add', 'संशोधन', 'जोड़ें'],
            'application' => ['आवेदन', 'applicant', 'entries', 'सूची'],
            'merit' => ['merit', 'ranking', 'rank', 'मेरिट', 'रैंक'],
            'support' => ['complaint', 'support', 'feedback', 'शिकायत', 'सहायता', 'सुझाव'],
        ];

        $topics = [];

        foreach ($topicMap as $topic => $signals) {
            $matched = collect($signals)->contains(fn (string $signal) => str_contains($normalized, $this->normalizeText($signal)));
            if ($matched) {
                $topics[] = $topic;
            }
        }

        return $topics;
    }

    /**
     * @param  string[]  $items
     * @return string[]
     */
    private function seededShuffle(array $items, int $seed): array
    {
        $decorated = collect($items)->map(function (string $item, int $index) use ($seed) {
            $weight = crc32($item.'|'.$seed.'|'.$index);

            return [
                'item' => $item,
                'weight' => $weight,
            ];
        });

        return $decorated
            ->sortBy('weight')
            ->pluck('item')
            ->values()
            ->all();
    }

    private function isLoginQuery(string $query): bool
    {
        return in_array($this->detectIntent($query), ['login', 'otp'], true);
    }

    private function isLoginSnippet(string $text): bool
    {
        $normalized = $this->normalizeText($text);

        return str_contains($normalized, 'login')
            || str_contains($normalized, 'log in')
            || str_contains($normalized, 'लॉगिन')
            || str_contains($normalized, 'लॉग इन')
            || str_contains($normalized, 'otp')
            || str_contains($normalized, 'password')
            || str_contains($normalized, 'पासवर्ड')
            || str_contains($normalized, 'link')
            || str_contains($normalized, 'लिंक')
            || str_contains($normalized, 'हलंक');
    }

    private function detectIntent(string $text): string
    {
        $normalized = $this->normalizeText($text);

        if (preg_match('/\b(otp|verify|verification|satyapan)\b/u', $normalized) === 1
            || str_contains($normalized, 'सत्यापन')) {
            return 'otp';
        }

        if (preg_match('/\b(login|log in|signin|sign in|password)\b/u', $normalized) === 1
            || str_contains($normalized, 'लॉगिन')
            || str_contains($normalized, 'लॉग इन')
            || str_contains($normalized, 'पासवर्ड')) {
            return 'login';
        }

        if (preg_match('/\b(advertisement|advertisment|notice|publish)\b/u', $normalized) === 1
            || str_contains($normalized, 'विज्ञापन')
            || str_contains($normalized, 'अधिसूचना')) {
            return 'advertisement';
        }

        if (preg_match('/\b(post|vacancy)\b/u', $normalized) === 1
            || str_contains($normalized, 'पोस्ट')
            || str_contains($normalized, 'रिक्ति')
            || (
                str_contains($normalized, 'पद')
                && (str_contains($normalized, 'add') || str_contains($normalized, 'create') || str_contains($normalized, 'update'))
            )) {
            return 'post';
        }

        if (preg_match('/\b(panchayat)\b/u', $normalized) === 1
            || str_contains($normalized, 'पंचायत')) {
            return 'panchayat';
        }

        if (preg_match('/\b(gram|village|ward|block)\b/u', $normalized) === 1
            || str_contains($normalized, 'ग्राम')
            || str_contains($normalized, 'वार्ड')
            || str_contains($normalized, 'ब्लॉक')) {
            return 'gram';
        }

        if (
            str_contains($normalized, 'master')
            || str_contains($normalized, 'मास्टर')
            || (
                (str_contains($normalized, 'add') || str_contains($normalized, 'create') || str_contains($normalized, 'update'))
                && (str_contains($normalized, 'mapping') || str_contains($normalized, 'संशोधन') || str_contains($normalized, 'जोड़'))
            )
        ) {
            return 'master';
        }

        if (preg_match('/\b(search|entries|find)\b/u', $normalized) === 1
            || str_contains($normalized, 'खोज')) {
            return 'search';
        }

        if (preg_match('/\b(download|manual|guide)\b/u', $normalized) === 1
            || str_contains($normalized, 'डाउनलोड')
            || str_contains($normalized, 'डाउन')
            || str_contains($normalized, 'मार्गदर्शिका')) {
            return 'download';
        }

        if (preg_match('/\b(contact|phone|email)\b/u', $normalized) === 1
            || str_contains($normalized, 'संपर्क')
            || str_contains($normalized, 'संपका')) {
            return 'contact';
        }

        if (preg_match('/\b(merit|ranking|rank)\b/u', $normalized) === 1
            || str_contains($normalized, 'मेरिट')
            || str_contains($normalized, 'रैंक')) {
            return 'merit';
        }

        if (preg_match('/\b(complaint|help|support|feedback|suggestion|grievance)\b/u', $normalized) === 1
            || str_contains($normalized, 'शिकायत')
            || str_contains($normalized, 'सहायता')
            || str_contains($normalized, 'सुझाव')
            || str_contains($normalized, 'प्रतिक्रिया')) {
            return 'complaint';
        }

        return 'general';
    }

    private function detectActionPreference(string $text): string
    {
        $normalized = $this->normalizeText($text);

        if (
            preg_match('/\b(update|edit|modify|change|revise)\b/u', $normalized) === 1
            || str_contains($normalized, 'संशोधन')
            || str_contains($normalized, 'अद्यतन')
            || str_contains($normalized, 'अपडेट')
        ) {
            return 'update';
        }

        if (
            preg_match('/\b(create|add|new|insert)\b/u', $normalized) === 1
            || str_contains($normalized, 'जोड़ें')
            || str_contains($normalized, 'नया')
            || str_contains($normalized, 'प्रविष्टि')
            || str_contains($normalized, 'निर्माण')
        ) {
            return 'create';
        }

        if (
            preg_match('/\b(publish|release|activate|live)\b/u', $normalized) === 1
            || str_contains($normalized, 'प्रकाशित')
            || str_contains($normalized, 'सक्रिय')
            || str_contains($normalized, 'जारी')
        ) {
            return 'publish';
        }

        if (
            preg_match('/\b(view|see|show|check|list|status|search)\b/u', $normalized) === 1
            || str_contains($normalized, 'देखें')
            || str_contains($normalized, 'सूची')
            || str_contains($normalized, 'स्थिति')
            || str_contains($normalized, 'खोज')
        ) {
            return 'view';
        }

        return 'none';
    }

    private function snippetMatchesAction(string $text, string $action): bool
    {
        $normalized = $this->normalizeText($text);

        return match ($action) {
            'update' => preg_match('/\b(update|edit|modify|change|revise)\b/u', $normalized) === 1
                || str_contains($normalized, 'संशोधन')
                || str_contains($normalized, 'अद्यतन')
                || str_contains($normalized, 'अपडेट'),
            'create' => preg_match('/\b(create|add|new|insert)\b/u', $normalized) === 1
                || str_contains($normalized, 'जोड़ें')
                || str_contains($normalized, 'नया')
                || str_contains($normalized, 'प्रविष्टि')
                || str_contains($normalized, 'निर्माण'),
            'publish' => preg_match('/\b(publish|release|activate|live)\b/u', $normalized) === 1
                || str_contains($normalized, 'प्रकाशित')
                || str_contains($normalized, 'सक्रिय')
                || str_contains($normalized, 'जारी'),
            'view' => preg_match('/\b(view|see|show|check|list|status|search|filter)\b/u', $normalized) === 1
                || str_contains($normalized, 'देखें')
                || str_contains($normalized, 'सूची')
                || str_contains($normalized, 'स्थिति')
                || str_contains($normalized, 'खोज')
                || str_contains($normalized, 'फ़िल्टर')
                || str_contains($normalized, 'फिल्टर'),
            default => true,
        };
    }

    private function snippetMatchesIntent(string $text, string $intent): bool
    {
        $normalized = $this->normalizeText($text);

        return match ($intent) {
            'login' => preg_match('/\b(login|log in|signin|password|captcha|portal)\b/u', $normalized) === 1
                || str_contains($normalized, 'लॉगिन')
                || str_contains($normalized, 'लॉग इन')
                || str_contains($normalized, 'पासवर्ड'),
            'otp' => preg_match('/\b(otp|verify|verification)\b/u', $normalized) === 1
                || str_contains($normalized, 'सत्यापन')
                || str_contains($normalized, 'ओटीपी'),
            'search' => preg_match('/\b(search|entries|find|filter)\b/u', $normalized) === 1
                || str_contains($normalized, 'खोज'),
            'download' => preg_match('/\b(download|file|manual|list)\b/u', $normalized) === 1
                || str_contains($normalized, 'डाउनलोड')
                || str_contains($normalized, 'सूची'),
            'contact' => preg_match('/\b(contact|phone|email|address)\b/u', $normalized) === 1
                || str_contains($normalized, 'संपर्क')
                || str_contains($normalized, 'फोन')
                || str_contains($normalized, 'ईमेल'),
            'merit' => preg_match('/\b(merit|ranking|rank|score|marks)\b/u', $normalized) === 1
                || str_contains($normalized, 'मेरिट')
                || str_contains($normalized, 'रैंक')
                || str_contains($normalized, 'अंक'),
            'complaint' => preg_match('/\b(complaint|help|support|feedback|suggestion|grievance)\b/u', $normalized) === 1
                || str_contains($normalized, 'शिकायत')
                || str_contains($normalized, 'सहायता')
                || str_contains($normalized, 'सुझाव')
                || str_contains($normalized, 'प्रतिक्रिया'),
            'advertisement' => preg_match('/\b(advertisement|advertisment|notice|publish)\b/u', $normalized) === 1
                || str_contains($normalized, 'विज्ञापन')
                || str_contains($normalized, 'अधिसूचना')
                || str_contains($normalized, 'प्रकाशित'),
            'post' => (
                preg_match('/\b(post|vacancy|position)\b/u', $normalized) === 1
                || str_contains($normalized, 'पोस्ट')
                || str_contains($normalized, 'रिक्ति')
                || str_contains($normalized, 'पद')
            ) && (
                str_contains($normalized, 'create')
                || str_contains($normalized, 'add')
                || str_contains($normalized, 'update')
                || str_contains($normalized, 'जोड़')
                || str_contains($normalized, 'संशोधन')
                || str_contains($normalized, 'submit')
            ),
            'gram' => preg_match('/\b(gram|village|ward|block|rural|urban)\b/u', $normalized) === 1
                || str_contains($normalized, 'ग्राम')
                || str_contains($normalized, 'वार्ड')
                || str_contains($normalized, 'ब्लॉक')
                || str_contains($normalized, 'ग्रामीण')
                || str_contains($normalized, 'शहरी'),
            'panchayat' => preg_match('/\b(panchayat)\b/u', $normalized) === 1
                || str_contains($normalized, 'पंचायत'),
            'master' => preg_match('/\b(master|mapping)\b/u', $normalized) === 1
                || str_contains($normalized, 'मास्टर')
                || str_contains($normalized, 'संशोधन')
                || str_contains($normalized, 'जोड़'),
            default => true,
        };
    }

    private function isTemplateLikeAnswer(string $answer): bool
    {
        $normalized = $this->normalizeText($answer);

        return str_contains($normalized, 'e bharti portal open karein')
            || str_contains($normalized, 'otp aaye to otp verify karke aage badhein')
            || str_contains($normalized, 'search box me keyword')
            || str_contains($normalized, 'download button par click');
    }

    private function hasCorruptedArtifacts(string $text): bool
    {
        if (str_contains($text, 'From manual:') || str_contains($text, 'From helpful history:')) {
            return true;
        }

        if (str_contains($text, 'C:\\Users\\')) {
            return true;
        }

        $normalized = $this->normalizeText($text);
        $tokens = [
            'हचत्र',
            'हवज्ञापन',
            'हववरण',
            'हजला',
            'हवकल्प',
            'आवेिन',
            'प्रिहशतत',
            'उपयोगकतात',
            'संबंहित',
            'अंहतम',
            'स्थिहत',
            'ह़िल्टर',
            'मागतिहशतका',
            'अंिरगतत',
            'प्रहवहियों',
            'हलए',
            'सुहविा',
            'हवशेर्',
            'अवेिक',
            'आवेिक',
        ];

        $hits = collect($tokens)->sum(
            fn (string $token) => str_contains($normalized, $this->normalizeText($token)) ? 1 : 0
        );

        return $hits >= 2;
    }

    private function fixCommonHindiOcrWords(string $text): string
    {
        $replacements = [
            'लॉहगन' => 'लॉगिन',
            'हलए' => 'लिए',
            'हिए' => 'दिए',
            'हलंक' => 'लिंक',
            'हवज्ञापन' => 'विज्ञापन',
            'प्रहवहियों' => 'प्रविष्टियों',
            'हजससे' => 'जिससे',
            'हकसी' => 'किसी',
            'हवशेर्' => 'विशेष',
            'अहिकारी' => 'अधिकारी',
            'सुहविा' => 'सुविधा',
            'मोबाईल' => 'मोबाइल',
            ' िै' => ' है',
            ' िैं' => ' हैं',
            'आवेिक' => 'आवेदक',
        ];

        return strtr($text, $replacements);
    }

    /**
     * @param  string[]  $relatedQuestions
     */
    private function streamLocalAnswer(
        int $responseId,
        string $answer,
        string $source,
        array $relatedQuestions = [],
        string $tonePreset = 'friendly'
    ): StreamedResponse {
        $invocationId = 'local-'.str_replace('.', '', uniqid('', true));
        $characters = preg_split('//u', $answer, -1, PREG_SPLIT_NO_EMPTY) ?: [];

        return response()->stream(function () use ($invocationId, $responseId, $source, $characters, $relatedQuestions, $tonePreset) {
            $timestamp = now()->timestamp;

            echo 'data: '.json_encode([
                'id' => $invocationId.'-start',
                'invocation_id' => $invocationId,
                'type' => 'stream_start',
                'provider' => 'local-index',
                'model' => 'manual-history-ranker',
                'timestamp' => $timestamp,
                'metadata' => null,
            ], JSON_UNESCAPED_UNICODE)."\n\n";

            echo 'data: '.json_encode([
                'id' => $invocationId.'-meta',
                'invocation_id' => $invocationId,
                'type' => 'response_meta',
                'response_id' => $responseId,
                'source' => $source,
                'related_questions' => $relatedQuestions,
                'tone' => $tonePreset,
                'timestamp' => $timestamp,
            ], JSON_UNESCAPED_UNICODE)."\n\n";

            foreach ($characters as $index => $char) {
                echo 'data: '.json_encode([
                    'id' => $invocationId.'-delta-'.$index,
                    'invocation_id' => $invocationId,
                    'type' => 'text_delta',
                    'message_id' => $invocationId.'-message',
                    'delta' => $char,
                    'timestamp' => now()->timestamp,
                ], JSON_UNESCAPED_UNICODE)."\n\n";
            }

            echo 'data: '.json_encode([
                'id' => $invocationId.'-end',
                'invocation_id' => $invocationId,
                'type' => 'stream_end',
                'reason' => 'stop',
                'usage' => [
                    'input_tokens' => 0,
                    'output_tokens' => 0,
                    'total_tokens' => 0,
                ],
                'timestamp' => now()->timestamp,
            ], JSON_UNESCAPED_UNICODE)."\n\n";

            echo "data: [DONE]\n\n";
        }, headers: [
            'Content-Type' => 'text/event-stream',
            'Cache-Control' => 'no-cache',
            'X-Accel-Buffering' => 'no',
        ]);
    }
}
