<?php

namespace App\Services;

use App\Models\ManualChunk;
use Illuminate\Support\Facades\File;
use Smalot\PdfParser\Parser;

class ManualKnowledgeService
{
    public function ensureIndexed(string $folder = 'chatbot_data_docs'): bool
    {
        $pdfPath = $this->firstPdfPath($folder);

        if ($pdfPath === null) {
            return false;
        }

        $hash = hash_file('sha256', $pdfPath);

        $alreadyIndexed = ManualChunk::query()
            ->where('source_path', $pdfPath)
            ->where('source_hash', $hash)
            ->exists();

        if ($alreadyIndexed) {
            return true;
        }

        ManualChunk::query()->where('source_path', $pdfPath)->delete();

        $text = $this->extractPdfText($pdfPath);
        $chunks = $this->splitIntoChunks($text);

        if ($chunks === []) {
            return false;
        }

        $now = now();

        ManualChunk::query()->insert(
            collect($chunks)->map(fn (string $chunk, int $index) => [
                'source_path' => $pdfPath,
                'source_hash' => $hash,
                'chunk_order' => $index + 1,
                'content' => $chunk,
                'created_at' => $now,
                'updated_at' => $now,
            ])->all()
        );

        return true;
    }

    /**
     * @return array<int, array{id:int, chunk_order:int, content:string, score:int}>
     */
    public function search(string $query, int $limit = 4): array
    {
        $normalizedQuery = preg_replace('/[^\p{L}\p{N}\s]/u', ' ', mb_strtolower($query)) ?? '';

        $terms = collect(preg_split('/\s+/', $normalizedQuery))
            ->filter(fn (?string $term) => $term && mb_strlen($term) >= 3)
            ->unique()
            ->values();

        $candidateQuery = ManualChunk::query()->select(['id', 'chunk_order', 'content']);

        if ($terms->isNotEmpty()) {
            $candidateQuery->where(function ($builder) use ($terms) {
                foreach ($terms as $term) {
                    $builder->orWhere('content', 'like', '%'.$term.'%');
                }
            });
        }

        $candidates = $candidateQuery
            ->orderBy('chunk_order')
            ->limit(120)
            ->get()
            ->all();

        if (empty($candidates)) {
            return ManualChunk::query()
                ->select(['id', 'chunk_order', 'content'])
                ->orderBy('chunk_order')
                ->limit($limit)
                ->get()
                ->map(fn (ManualChunk $chunk) => [
                    'id' => $chunk->id,
                    'chunk_order' => $chunk->chunk_order,
                    'content' => $chunk->content,
                    'score' => 0,
                ])
                ->all();
        }

        $scored = collect($candidates)
            ->map(function (ManualChunk $chunk) use ($terms) {
                $text = mb_strtolower($chunk->content);
                $score = $terms->sum(fn (string $term) => substr_count($text, $term));

                return [
                    'id' => $chunk->id,
                    'chunk_order' => $chunk->chunk_order,
                    'content' => $chunk->content,
                    'score' => $score,
                ];
            })
            ->sortByDesc('score')
            ->take($limit)
            ->values()
            ->all();

        return $scored;
    }

    /**
     * @param  array<int, array{id:int, chunk_order:int, content:string, score:int}>  $chunks
     */
    public function contextFromChunks(array $chunks): string
    {
        if ($chunks === []) {
            return 'No relevant manual context found.';
        }

        $maxScore = collect($chunks)->max('score') ?? 0;
        if ($maxScore <= 0) {
            return 'No relevant manual context found.';
        }

        return collect($chunks)
            ->map(fn (array $chunk) => "[Chunk {$chunk['chunk_order']} | score {$chunk['score']}]\n{$chunk['content']}")
            ->join("\n\n");
    }

    private function firstPdfPath(string $folder): ?string
    {
        $directory = public_path($folder);

        if (! File::isDirectory($directory)) {
            return null;
        }

        $pdfFile = collect(File::files($directory))
            ->first(fn (\SplFileInfo $file) => strtolower($file->getExtension()) === 'pdf');

        return $pdfFile?->getRealPath();
    }

    private function extractPdfText(string $pdfPath): string
    {
        $text = (new Parser)->parseFile($pdfPath)->getText();

        return trim((string) preg_replace('/\s+/u', ' ', $text));
    }

    /**
     * @return string[]
     */
    private function splitIntoChunks(string $text, int $chunkWords = 180, int $overlapWords = 30): array
    {
        if ($text === '') {
            return [];
        }

        $words = preg_split('/\s+/u', $text) ?: [];
        $words = array_values(array_filter($words, fn (string $word) => $word !== ''));

        if ($words === []) {
            return [];
        }

        $step = max(1, $chunkWords - $overlapWords);
        $chunks = [];

        for ($start = 0; $start < count($words); $start += $step) {
            $slice = array_slice($words, $start, $chunkWords);
            if ($slice === []) {
                break;
            }

            $chunks[] = trim(implode(' ', $slice));
        }

        return $chunks;
    }
}
