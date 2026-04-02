<?php

namespace App\Services;

use App\Models\ManualChunk;
use DOMDocument;
use DOMElement;
use DOMNode;
use DOMNodeList;
use DOMXPath;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\File;
use ZipArchive;

class ManualKnowledgeService
{
    private const INDEX_VERSION = 'v9-docx-meta-question-map';

    public function ensureIndexed(string $folder = 'chatbot_data_docs'): bool
    {
        $documentPath = $this->firstDocumentPath($folder);

        if ($documentPath === null) {
            return false;
        }

        $fileHash = hash_file('sha256', $documentPath);
        $hash = hash('sha256', $fileHash.'|'.self::INDEX_VERSION);

        $alreadyIndexed = ManualChunk::query()
            ->where('source_path', $documentPath)
            ->where('source_hash', $hash)
            ->exists();

        if ($alreadyIndexed) {
            return true;
        }

        // Keep only the latest document index to avoid stale PDF-era chunks.
        ManualChunk::query()->delete();

        $paragraphs = $this->extractDocumentParagraphs($documentPath);
        $chunks = $this->buildChunksFromParagraphs($paragraphs);

        if ($chunks === []) {
            return false;
        }

        $now = now();

        ManualChunk::query()->insert(
            collect($chunks)->map(fn (array $chunk, int $index) => [
                'source_path' => $documentPath,
                'source_hash' => $hash,
                'chunk_order' => $index + 1,
                'content' => $chunk['content'],
                'metadata' => json_encode($chunk['metadata'], JSON_UNESCAPED_UNICODE),
                'created_at' => $now,
                'updated_at' => $now,
            ])->all()
        );

        return true;
    }

    /**
     * @return array<int, array{
     *     id:int,
     *     chunk_order:int,
     *     content:string,
     *     score:int,
     *     matched_question:?string,
     *     question_overlap:int,
     *     metadata:array<string, mixed>
     * }>
     */
    public function search(string $query, int $limit = 4): array
    {
        $terms = $this->queryTerms($query);
        $normalizedQuery = $this->normalizeForKeywords($query);
        $minimumOverlap = $this->minimumKeywordOverlap($terms->count());

        $candidates = ManualChunk::query()
            ->select(['id', 'chunk_order', 'content', 'metadata'])
            ->orderBy('chunk_order')
            ->limit(400)
            ->get();

        if ($candidates->isEmpty()) {
            return [];
        }

        $scored = $candidates
            ->map(function (ManualChunk $chunk) use ($terms, $normalizedQuery) {
                $metadata = is_array($chunk->metadata) ? $chunk->metadata : [];
                $keywords = collect($metadata['keywords'] ?? [])
                    ->map(fn ($keyword) => mb_strtolower((string) $keyword))
                    ->filter()
                    ->values();

                $contentText = mb_strtolower($chunk->content);
                $questionMatch = $this->evaluateQuestionMatch(
                    normalizedQuery: $normalizedQuery,
                    queryTerms: $terms,
                    candidateQuestions: is_array($metadata['candidate_questions'] ?? null) ? $metadata['candidate_questions'] : [],
                    questionKeywords: is_array($metadata['question_keywords'] ?? null) ? $metadata['question_keywords'] : [],
                );

                $metaScore = $terms->sum(function (string $term) use ($keywords) {
                    return $keywords->sum(
                        fn (string $keyword) => $keyword === $term || str_contains($keyword, $term) ? 1 : 0
                    );
                });

                $contentScore = $terms->sum(fn (string $term) => substr_count($contentText, $term));
                $hasSignalMatch = ($metaScore + $contentScore + $questionMatch['score']) > 0;
                $headingBoost = $hasSignalMatch && ($metadata['heading_like'] ?? false) ? 1 : 0;
                $score = ($questionMatch['score'] * 3) + ($metaScore * 6) + ($contentScore * 2) + $headingBoost;

                return [
                    'id' => $chunk->id,
                    'chunk_order' => $chunk->chunk_order,
                    'content' => $chunk->content,
                    'metadata' => $metadata,
                    'meta_score' => $metaScore,
                    'content_score' => $contentScore,
                    'question_score' => $questionMatch['score'],
                    'question_overlap' => $questionMatch['overlap'],
                    'matched_question' => $questionMatch['question'],
                    'score' => $score,
                ];
            });

        $questionMatched = $scored
            ->filter(fn (array $row) => $row['question_score'] > 0 && $row['question_overlap'] >= $minimumOverlap)
            ->sortByDesc('score')
            ->sortByDesc('question_score')
            ->take($limit)
            ->values()
            ->all();

        if ($questionMatched !== []) {
            return $this->compactSearchRows($questionMatched);
        }

        $softQuestionMatched = $scored
            ->filter(fn (array $row) => $row['question_score'] > 0)
            ->sortByDesc('score')
            ->sortByDesc('question_score')
            ->take($limit)
            ->values()
            ->all();

        if ($softQuestionMatched !== []) {
            return $this->compactSearchRows($softQuestionMatched);
        }

        $metaMatched = $scored
            ->filter(fn (array $row) => $row['meta_score'] > 0)
            ->sortByDesc('score')
            ->sortByDesc('meta_score')
            ->take($limit)
            ->values()
            ->all();

        if ($metaMatched !== []) {
            return $this->compactSearchRows($metaMatched);
        }

        $contentMatched = $scored
            ->filter(fn (array $row) => $row['content_score'] > 0)
            ->sortByDesc('score')
            ->take($limit)
            ->values()
            ->all();

        if ($contentMatched !== []) {
            return $this->compactSearchRows($contentMatched);
        }

        return $this->compactSearchRows($scored
            ->take($limit)
            ->values()
            ->all());
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

    private function firstDocumentPath(string $folder): ?string
    {
        $directory = public_path($folder);

        if (! File::isDirectory($directory)) {
            return null;
        }

        $files = collect(File::files($directory));
        $priorityExtensions = ['docx', 'txt', 'md'];

        foreach ($priorityExtensions as $extension) {
            /** @var \SplFileInfo|null $matched */
            $matched = $files
                ->filter(fn (\SplFileInfo $file) => strtolower($file->getExtension()) === $extension)
                ->sortByDesc(fn (\SplFileInfo $file) => $file->getMTime())
                ->first();

            if ($matched !== null) {
                return $matched->getRealPath();
            }
        }

        return null;
    }

    /**
     * @return array<int, array{content:string, metadata:array<string, mixed>}>
     */
    private function extractDocumentParagraphs(string $documentPath): array
    {
        $extension = strtolower(pathinfo($documentPath, PATHINFO_EXTENSION));

        if ($extension === 'docx') {
            return $this->extractDocxParagraphs($documentPath);
        }

        if (! File::exists($documentPath)) {
            return [];
        }

        $text = (string) File::get($documentPath);

        return collect(preg_split('/\R+/u', $text) ?: [])
            ->map(fn (string $line) => trim($line))
            ->filter(fn (string $line) => $line !== '')
            ->map(fn (string $line) => [
                'content' => $line,
                'metadata' => [
                    'keywords' => $this->extractKeywords($line),
                    'paragraph_style_id' => null,
                    'paragraph_style_name' => null,
                    'heading_like' => false,
                    'fonts' => [],
                    'font_sizes' => [],
                    'bold_ratio' => 0,
                    'italic_ratio' => 0,
                ],
            ])
            ->values()
            ->all();
    }

    /**
     * @return array<int, array{content:string, metadata:array<string, mixed>}>
     */
    private function extractDocxParagraphs(string $documentPath): array
    {
        $zip = new ZipArchive;
        if ($zip->open($documentPath) !== true) {
            return [];
        }

        $documentXml = $zip->getFromName('word/document.xml');
        $stylesXml = $zip->getFromName('word/styles.xml') ?: '';
        $zip->close();

        if (! is_string($documentXml) || trim($documentXml) === '') {
            return [];
        }

        $styleMap = $this->parseDocxStyles($stylesXml);

        $dom = new DOMDocument('1.0', 'UTF-8');
        $loaded = @$dom->loadXML($documentXml, LIBXML_NOERROR | LIBXML_NOWARNING | LIBXML_NONET);
        if (! $loaded) {
            return [];
        }

        $xpath = new DOMXPath($dom);
        $xpath->registerNamespace('w', 'http://schemas.openxmlformats.org/wordprocessingml/2006/main');
        $paragraphNodes = $xpath->query('//w:body/w:p');

        if (! $paragraphNodes instanceof DOMNodeList || $paragraphNodes->length === 0) {
            return [];
        }

        $paragraphs = [];

        foreach ($paragraphNodes as $paragraphNode) {
            if (! $paragraphNode instanceof DOMNode) {
                continue;
            }

            $runs = $xpath->query('.//w:r', $paragraphNode);
            if (! $runs instanceof DOMNodeList || $runs->length === 0) {
                continue;
            }

            $runTexts = [];
            $fonts = [];
            $fontSizes = [];
            $boldChars = 0;
            $italicChars = 0;
            $totalChars = 0;

            foreach ($runs as $runNode) {
                if (! $runNode instanceof DOMNode) {
                    continue;
                }

                $textNodes = $xpath->query('.//w:t', $runNode);
                if (! $textNodes instanceof DOMNodeList || $textNodes->length === 0) {
                    continue;
                }

                $rawText = collect(iterator_to_array($textNodes))
                    ->map(fn ($textNode) => $textNode instanceof DOMNode ? (string) $textNode->textContent : '')
                    ->join('');

                $runText = trim((string) preg_replace('/\s+/u', ' ', $rawText));
                if ($runText === '') {
                    continue;
                }

                $isBold = $this->hasXPathNode($xpath, './/w:rPr/w:b | .//w:rPr/w:bCs', $runNode);
                $isItalic = $this->hasXPathNode($xpath, './/w:rPr/w:i | .//w:rPr/w:iCs', $runNode);

                $fontNode = $xpath->query('.//w:rPr/w:rFonts', $runNode)?->item(0);
                $fontName = $fontNode instanceof DOMElement
                    ? $this->getNodeAttribute($fontNode, ['w:ascii', 'w:hAnsi', 'w:cs', 'ascii', 'hAnsi', 'cs'])
                    : null;
                if ($fontName !== null && $fontName !== '') {
                    $fonts[] = $fontName;
                }

                $fontSizeNode = $xpath->query('.//w:rPr/w:sz', $runNode)?->item(0);
                $fontSize = $fontSizeNode instanceof DOMElement
                    ? $this->getNodeAttribute($fontSizeNode, ['w:val', 'val'])
                    : null;
                if ($fontSize !== null && $fontSize !== '') {
                    $fontSizes[] = $fontSize;
                }

                $charCount = mb_strlen($runText);
                $totalChars += $charCount;
                if ($isBold) {
                    $boldChars += $charCount;
                }
                if ($isItalic) {
                    $italicChars += $charCount;
                }

                $runTexts[] = $runText;
            }

            $paragraphText = trim((string) preg_replace('/\s+/u', ' ', implode(' ', $runTexts)));
            if ($paragraphText === '') {
                continue;
            }

            $styleNode = $xpath->query('./w:pPr/w:pStyle', $paragraphNode)?->item(0);
            $styleId = $styleNode instanceof DOMElement
                ? $this->getNodeAttribute($styleNode, ['w:val', 'val'])
                : null;
            $styleName = $styleId && isset($styleMap[$styleId]) ? $styleMap[$styleId]['name'] : null;
            $headingLike = ($styleId && ($styleMap[$styleId]['heading'] ?? false))
                || $this->looksLikeHeading($paragraphText, $styleName);

            $paragraphs[] = [
                'content' => $paragraphText,
                'metadata' => [
                    'keywords' => $this->extractKeywords($paragraphText, $styleName ? [$styleName] : []),
                    'paragraph_style_id' => $styleId,
                    'paragraph_style_name' => $styleName,
                    'heading_like' => $headingLike,
                    'fonts' => array_values(array_unique(array_filter($fonts))),
                    'font_sizes' => array_values(array_unique(array_filter($fontSizes))),
                    'bold_ratio' => $totalChars > 0 ? round($boldChars / $totalChars, 3) : 0,
                    'italic_ratio' => $totalChars > 0 ? round($italicChars / $totalChars, 3) : 0,
                ],
            ];
        }

        return $paragraphs;
    }

    /**
     * @return array<string, array{name:string, heading:bool}>
     */
    private function parseDocxStyles(string $stylesXml): array
    {
        if (trim($stylesXml) === '') {
            return [];
        }

        $dom = new DOMDocument('1.0', 'UTF-8');
        $loaded = @$dom->loadXML($stylesXml, LIBXML_NOERROR | LIBXML_NOWARNING | LIBXML_NONET);
        if (! $loaded) {
            return [];
        }

        $xpath = new DOMXPath($dom);
        $xpath->registerNamespace('w', 'http://schemas.openxmlformats.org/wordprocessingml/2006/main');

        $styleNodes = $xpath->query('//w:style');
        if (! $styleNodes instanceof DOMNodeList || $styleNodes->length === 0) {
            return [];
        }

        $styles = [];

        foreach ($styleNodes as $styleNode) {
            if (! $styleNode instanceof DOMElement) {
                continue;
            }

            $styleId = $this->getNodeAttribute($styleNode, ['w:styleId', 'styleId']);
            if ($styleId === null || $styleId === '') {
                continue;
            }

            $nameNode = $xpath->query('./w:name', $styleNode)?->item(0);
            $styleName = $nameNode instanceof DOMElement
                ? $this->getNodeAttribute($nameNode, ['w:val', 'val'])
                : null;

            $outlineNode = $xpath->query('./w:pPr/w:outlineLvl', $styleNode)?->item(0);
            $heading = $outlineNode instanceof DOMElement;

            if ($styleName !== null && preg_match('/heading|title|subtitle/i', $styleName) === 1) {
                $heading = true;
            }

            $styles[$styleId] = [
                'name' => $styleName ?? $styleId,
                'heading' => $heading,
            ];
        }

        return $styles;
    }

    /**
     * @param  array<int, array{content:string, metadata:array<string, mixed>}>  $paragraphs
     * @return array<int, array{content:string, metadata:array<string, mixed>}>
     */
    private function buildChunksFromParagraphs(array $paragraphs, int $maxChars = 1000): array
    {
        if ($paragraphs === []) {
            return [];
        }

        $chunks = [];
        $buffer = [];
        $keywordBucket = [];
        $styleNames = [];
        $fonts = [];
        $fontSizes = [];
        $boldRatios = [];
        $italicRatios = [];
        $heading = null;
        $headingLike = false;

        $flushChunk = function () use (
            &$chunks,
            &$buffer,
            &$keywordBucket,
            &$styleNames,
            &$fonts,
            &$fontSizes,
            &$boldRatios,
            &$italicRatios,
            &$heading,
            &$headingLike
        ): void {
            if ($buffer === []) {
                return;
            }

            $content = trim(implode("\n", $buffer));
            if ($content === '') {
                return;
            }

            $allKeywords = collect($keywordBucket)
                ->map(fn ($keyword) => (string) $keyword)
                ->filter(fn (string $keyword) => $keyword !== '')
                ->unique()
                ->values();

            $topKeywords = collect($keywordBucket)
                ->map(fn ($keyword) => (string) $keyword)
                ->filter(fn (string $keyword) => $keyword !== '')
                ->countBy()
                ->sortDesc()
                ->keys()
                ->take(28)
                ->values();

            $keywordList = $topKeywords
                ->merge($allKeywords)
                ->unique()
                ->take(80)
                ->values()
                ->all();
            $candidateQuestions = $this->generateCandidateQuestions(
                content: $content,
                heading: $heading,
                keywords: $keywordList
            );
            $questionKeywords = collect($candidateQuestions)
                ->flatMap(fn (string $question) => $this->extractKeywords($question))
                ->merge($keywordList)
                ->unique()
                ->take(120)
                ->values()
                ->all();

            $chunks[] = [
                'content' => $content,
                'metadata' => [
                    'keywords' => $keywordList,
                    'candidate_questions' => $candidateQuestions,
                    'question_keywords' => $questionKeywords,
                    'heading' => $heading,
                    'heading_like' => $headingLike,
                    'styles' => array_values(array_unique(array_filter($styleNames))),
                    'fonts' => array_values(array_unique(array_filter($fonts))),
                    'font_sizes' => array_values(array_unique(array_filter($fontSizes))),
                    'paragraph_count' => count($buffer),
                    'bold_ratio' => count($boldRatios) > 0 ? round(array_sum($boldRatios) / count($boldRatios), 3) : 0,
                    'italic_ratio' => count($italicRatios) > 0 ? round(array_sum($italicRatios) / count($italicRatios), 3) : 0,
                ],
            ];

            $buffer = [];
            $keywordBucket = [];
            $styleNames = [];
            $fonts = [];
            $fontSizes = [];
            $boldRatios = [];
            $italicRatios = [];
            $heading = null;
            $headingLike = false;
        };

        foreach ($paragraphs as $paragraph) {
            $content = trim((string) ($paragraph['content'] ?? ''));
            $metadata = $paragraph['metadata'] ?? [];

            if ($content === '') {
                continue;
            }

            $content = $this->cleanIndexText($content);
            if ($content === '') {
                continue;
            }

            $isHeading = (bool) ($metadata['heading_like'] ?? false);
            $currentText = trim(implode("\n", $buffer));
            $nextSize = mb_strlen($currentText === '' ? $content : $currentText."\n".$content);

            if ($buffer !== [] && ($isHeading || $nextSize > $maxChars)) {
                $flushChunk();
            }

            if ($buffer === []) {
                $heading = $isHeading ? $content : null;
                $headingLike = $isHeading;
            } elseif ($isHeading && $heading === null) {
                $heading = $content;
                $headingLike = true;
            }

            $buffer[] = $content;

            foreach ($metadata['keywords'] ?? [] as $keyword) {
                $keywordBucket[] = $keyword;
            }

            if (($metadata['paragraph_style_name'] ?? null) !== null) {
                $styleNames[] = (string) $metadata['paragraph_style_name'];
            }

            foreach ($metadata['fonts'] ?? [] as $font) {
                $fonts[] = $font;
            }

            foreach ($metadata['font_sizes'] ?? [] as $size) {
                $fontSizes[] = $size;
            }

            $boldRatios[] = (float) ($metadata['bold_ratio'] ?? 0);
            $italicRatios[] = (float) ($metadata['italic_ratio'] ?? 0);
        }

        $flushChunk();

        return $chunks;
    }

    private function looksLikeHeading(string $text, ?string $styleName): bool
    {
        if ($styleName !== null && preg_match('/heading|title|subtitle/i', $styleName) === 1) {
            return true;
        }

        $trimmed = trim($text);
        if ($trimmed === '' || mb_strlen($trimmed) > 120) {
            return false;
        }

        if (preg_match('/[:\-–—]$/u', $trimmed) === 1) {
            return true;
        }

        return preg_match('/^(login|otp|dashboard|reports|profile|help|सहायता|लॉगिन|डैशबोर्ड|रिपोर्ट)/iu', $trimmed) === 1;
    }

    private function hasXPathNode(DOMXPath $xpath, string $expression, DOMNode $context): bool
    {
        $nodeList = $xpath->query($expression, $context);

        return $nodeList instanceof DOMNodeList && $nodeList->length > 0;
    }

    private function getNodeAttribute(DOMElement $element, array $possibleNames): ?string
    {
        foreach ($possibleNames as $name) {
            if ($element->hasAttribute($name)) {
                return $element->getAttribute($name);
            }
        }

        return null;
    }

    private function cleanIndexText(string $text): string
    {
        $text = preg_replace('/[A-Za-z]:\\\\[^\s]+/u', ' ', $text) ?? $text;
        $text = preg_replace('/\S+\.(docx|pdf)/iu', ' ', $text) ?? $text;
        $text = str_replace(['•', "\u{00A0}"], [' ', ' '], $text);
        $text = trim((string) preg_replace('/\s+/u', ' ', $text));

        return $text;
    }

    /**
     * @param  array<int, array<string, mixed>>  $rows
     * @return array<int, array{
     *     id:int,
     *     chunk_order:int,
     *     content:string,
     *     score:int,
     *     matched_question:?string,
     *     question_overlap:int,
     *     metadata:array<string, mixed>
     * }>
     */
    private function compactSearchRows(array $rows): array
    {
        return collect($rows)
            ->map(fn (array $row) => [
                'id' => (int) ($row['id'] ?? 0),
                'chunk_order' => (int) ($row['chunk_order'] ?? 0),
                'content' => (string) ($row['content'] ?? ''),
                'score' => (int) ($row['score'] ?? 0),
                'matched_question' => isset($row['matched_question']) && is_string($row['matched_question'])
                    ? trim($row['matched_question'])
                    : null,
                'question_overlap' => (int) ($row['question_overlap'] ?? 0),
                'metadata' => is_array($row['metadata'] ?? null) ? $row['metadata'] : [],
            ])
            ->values()
            ->all();
    }

    private function minimumKeywordOverlap(int $queryTermCount): int
    {
        if ($queryTermCount >= 8) {
            return 4;
        }

        if ($queryTermCount >= 4) {
            return 3;
        }

        if ($queryTermCount >= 2) {
            return 2;
        }

        return 1;
    }

    /**
     * @param  Collection<int, string>  $queryTerms
     * @param  array<int, mixed>  $candidateQuestions
     * @param  array<int, mixed>  $questionKeywords
     * @return array{score:int,overlap:int,question:?string}
     */
    private function evaluateQuestionMatch(
        string $normalizedQuery,
        Collection $queryTerms,
        array $candidateQuestions,
        array $questionKeywords
    ): array {
        $bestScore = 0;
        $bestOverlap = 0;
        $bestQuestion = null;

        foreach ($candidateQuestions as $candidate) {
            if (! is_string($candidate)) {
                continue;
            }

            $question = trim($candidate);
            if ($question === '') {
                continue;
            }

            $normalizedQuestion = $this->normalizeForKeywords($question);
            if ($normalizedQuestion === '') {
                continue;
            }

            $questionTerms = collect(preg_split('/\s+/u', $normalizedQuestion))
                ->filter(fn (?string $term) => $term && mb_strlen($term) >= 2)
                ->reject(fn (string $term) => in_array($term, $this->stopwords(), true))
                ->unique()
                ->values();

            $overlap = $queryTerms->intersect($questionTerms)->count();
            $containsBoost = $normalizedQuery !== '' && (
                str_contains($normalizedQuestion, $normalizedQuery)
                || str_contains($normalizedQuery, $normalizedQuestion)
            );
            $coverageBoost = $queryTerms->count() > 0
                ? (int) round(($overlap / max(1, $queryTerms->count())) * 4)
                : 0;
            $score = ($overlap * 10) + ($containsBoost ? 10 : 0) + $coverageBoost;

            if (
                $score > $bestScore
                || ($score === $bestScore && $overlap > $bestOverlap)
            ) {
                $bestScore = $score;
                $bestOverlap = $overlap;
                $bestQuestion = $question;
            }
        }

        $normalizedQuestionKeywords = collect($questionKeywords)
            ->filter(fn ($keyword) => is_scalar($keyword))
            ->map(fn ($keyword) => $this->normalizeForKeywords((string) $keyword))
            ->filter(fn (string $keyword) => $keyword !== '')
            ->unique()
            ->values();

        $keywordOverlap = $queryTerms->sum(function (string $term) use ($normalizedQuestionKeywords) {
            return $normalizedQuestionKeywords->contains(
                fn (string $keyword) => $keyword === $term
                    || str_contains($keyword, $term)
                    || str_contains($term, $keyword)
            ) ? 1 : 0;
        });

        return [
            'score' => (int) ($bestScore + ($keywordOverlap * 3)),
            'overlap' => max($bestOverlap, (int) $keywordOverlap),
            'question' => $bestQuestion,
        ];
    }

    /**
     * @param  array<int, string>  $keywords
     * @return string[]
     */
    private function generateCandidateQuestions(string $content, ?string $heading, array $keywords): array
    {
        $questions = collect();
        $cleanHeading = $heading !== null
            ? trim((string) preg_replace('/[।\.\!\?]+$/u', '', $heading))
            : '';
        $headingTokenCount = count(array_values(array_filter(
            preg_split('/\s+/u', $this->normalizeForKeywords($cleanHeading)) ?: []
        )));

        if (
            $cleanHeading !== ''
            && mb_strlen($cleanHeading) >= 3
            && mb_strlen($cleanHeading) <= 60
            && $headingTokenCount <= 9
        ) {
            $questions->push($cleanHeading.' kaise kare?');
            $questions->push($cleanHeading.' process kya hai?');
        }

        foreach ($this->detectQuestionTopics($content, $keywords) as $topic) {
            $questions = $questions->merge($this->topicQuestionTemplatesForContent($topic, $content));
        }

        if ($questions->isEmpty()) {
            $questions = $questions->merge([
                'Is feature ka use kaise kare?',
                'Is process ko step by step kaise kare?',
                'Is section me kya update karna hota hai?',
            ]);
        }

        return $questions
            ->map(fn ($question) => trim((string) $question))
            ->filter(fn (string $question) => $question !== '')
            ->unique()
            ->take(14)
            ->values()
            ->all();
    }

    /**
     * @param  array<int, string>  $keywords
     * @return string[]
     */
    private function detectQuestionTopics(string $content, array $keywords = []): array
    {
        $normalized = $this->normalizeForKeywords($content.' '.implode(' ', $keywords));
        $containsAny = function (array $signals) use ($normalized): bool {
            return collect($signals)->contains(
                fn (string $signal) => str_contains($normalized, $this->normalizeForKeywords($signal))
            );
        };
        $containsCount = function (array $signals) use ($normalized): int {
            return collect($signals)
                ->filter(fn (string $signal) => str_contains($normalized, $this->normalizeForKeywords($signal)))
                ->count();
        };

        $scores = [];

        if ($containsAny(['login', 'signin', 'sign in', 'लॉगिन', 'log in'])) {
            $scores['login'] = 3 + $containsCount(['otp', 'password', 'पासवर्ड', 'dashboard', 'डैशबोर्ड']);
        }

        if ($containsAny(['advertisement', 'advertisment', 'notice', 'विज्ञापन', 'अधिसूचना'])) {
            $scores['advertisement'] = 3 + $containsCount(['publish', 'edit', 'update', 'जारी', 'प्रकाशित']);
        }

        if (
            (
                $containsAny(['post', 'पोस्ट', 'vacancy', 'रिक्ति'])
                || ($containsAny(['पद']) && $containsAny(['create', 'add', 'update', 'जोड़ें', 'संशोधन', 'नया']))
            )
            && $containsAny(['create', 'add', 'update', 'जोड़ें', 'संशोधन', 'नया', 'vacancy', 'रिक्ति'])
        ) {
            $scores['post'] = 3 + $containsCount(['post', 'पोस्ट', 'vacancy', 'रिक्ति', 'create', 'update']);
        }

        if ($containsAny(['ग्राम', 'village', 'ward', 'block', 'क्षेत्र'])) {
            $scores['gram'] = 2 + $containsCount(['चयन', 'select', 'mapping', 'rural', 'urban', 'ग्रामीण', 'शहरी']);
        }

        if ($containsAny(['पंचायत', 'panchayat'])) {
            $scores['panchayat'] = 3 + $containsCount(['master', 'mapping', 'add', 'update', 'जोड़ें', 'संशोधन']);
        }

        if (
            $containsAny(['master'])
            && $containsAny(['add', 'update', 'create', 'edit', 'जोड़ें', 'संशोधन'])
        ) {
            $scores['master'] = 3 + $containsCount(['master', 'table', 'mapping', 'जोड़ें', 'संशोधन']);
        }

        if (
            $containsAny(['आवेदन', 'आवेदक', 'application', 'applicant'])
            && $containsAny(['सूची', 'list', 'search', 'entries', 'status', 'स्थिति', 'देखें'])
        ) {
            $scores['application'] = 3 + $containsCount(['सूची', 'list', 'search', 'filter', 'entries']);
        }

        if ($containsAny(['merit', 'ranking', 'rank', 'मेरिट', 'रैंक', 'अंक'])) {
            $scores['merit'] = 3 + $containsCount(['marks', 'score', 'अंक', 'योग्यता', 'ranking']);
        }

        if ($containsAny(['complaint', 'support', 'feedback', 'suggestion', 'शिकायत', 'सहायता', 'सुझाव'])) {
            $scores['support'] = 3 + $containsCount(['status', 'track', 'submit', 'प्रतिक्रिया']);
        }

        if ($containsAny(['report', 'download', 'csv', 'excel', 'रिपोर्ट', 'डाउनलोड'])) {
            $scores['report'] = 3 + $containsCount(['filter', 'export', 'excel', 'csv', 'डाउनलोड']);
        }

        arsort($scores);

        return array_slice(array_keys($scores), 0, 3);
    }

    /**
     * @return array<string, array<int, string>>
     */
    private function topicQuestionTemplates(): array
    {
        return [
            'login' => [
                'Login process kya hai?',
                'Portal me login kaise kare?',
                'OTP verify kaise kare?',
                'Password reset ka process kya hai?',
                'Login ke baad dashboard kahan milega?',
            ],
            'advertisement' => [
                'Advertisement management ka process kya hai?',
                'Advertisement kaise create kare?',
                'Advertisement publish kaise kare?',
                'Advertisement update kaise kare?',
            ],
            'post' => [
                'Post management ka process kya hai?',
                'Post kaise create kare?',
                'Post details kaise update kare?',
                'Vacancy details kaise set kare?',
            ],
            'gram' => [
                'Gram selection ka process kya hai?',
                'Gram ka chayan kaise kare?',
                'Rural/Urban area type kaise select kare?',
                'District-Block-Village mapping kaise kare?',
            ],
            'panchayat' => [
                'Gram Panchayat process kya hai?',
                'Gram Panchayat master add kaise kare?',
                'Gram Panchayat master update kaise kare?',
                'Ward/Panchayat mapping kaise update kare?',
            ],
            'master' => [
                'Master data management ka process kya hai?',
                'Master data add kaise kare?',
                'Master data update kaise kare?',
                'Duplicate entry ko kaise handle kare?',
            ],
            'application' => [
                'Application management ka process kya hai?',
                'Aavedan list kaise dekhen?',
                'Applicant details kaise open kare?',
                'Search/filter ka use kaise kare?',
            ],
            'merit' => [
                'Merit process kya hai?',
                'Merit list kaise nikale?',
                'Ranking kis basis par hoti hai?',
                'Marks entry ka process kya hai?',
            ],
            'support' => [
                'Support process kya hai?',
                'Complaint ya suggestion kaise bhejein?',
                'Support request ka status kaise check kare?',
                'Feedback submit kaise kare?',
            ],
            'report' => [
                'Report process kya hai?',
                'Report download kaise kare?',
                'Excel/CSV export kaise kare?',
                'Report filter karke kaise nikale?',
            ],
        ];
    }

    /**
     * @return string[]
     */
    private function topicQuestionTemplatesForContent(string $topic, string $content): array
    {
        $templates = $this->topicQuestionTemplates()[$topic] ?? [];
        if ($templates === []) {
            return [];
        }

        $normalized = $this->normalizeForKeywords($content);
        $hasSignal = function (array $signals) use ($normalized): bool {
            return collect($signals)->contains(
                fn (string $signal) => str_contains($normalized, $this->normalizeForKeywords($signal))
            );
        };

        $actionSignals = [
            'create' => $hasSignal(['create', 'add', 'new', 'insert', 'जोड़ें', 'नया', 'प्रविष्टि']),
            'update' => $hasSignal(['update', 'edit', 'modify', 'change', 'संशोधन', 'अद्यतन', 'अपडेट']),
            'publish' => $hasSignal(['publish', 'release', 'activate', 'live', 'प्रकाशित', 'सक्रिय', 'जारी']),
            'view' => $hasSignal(['view', 'see', 'show', 'check', 'list', 'status', 'search', 'देखें', 'सूची', 'स्थिति', 'खोज']),
        ];

        $filtered = collect($templates)
            ->filter(function (string $template) use ($actionSignals) {
                $action = $this->questionTemplateAction($template);

                return $action === 'none' || ($actionSignals[$action] ?? false);
            })
            ->values()
            ->all();

        if ($filtered !== []) {
            return $filtered;
        }

        return collect($templates)
            ->filter(fn (string $template) => $this->questionTemplateAction($template) === 'none')
            ->values()
            ->all();
    }

    private function questionTemplateAction(string $template): string
    {
        $normalized = $this->normalizeForKeywords($template);

        if (
            str_contains($normalized, 'update')
            || str_contains($normalized, 'edit')
            || str_contains($normalized, 'modify')
            || str_contains($normalized, 'संशोधन')
            || str_contains($normalized, 'अद्यतन')
            || str_contains($normalized, 'अपडेट')
        ) {
            return 'update';
        }

        if (
            str_contains($normalized, 'create')
            || str_contains($normalized, 'add')
            || str_contains($normalized, 'new')
            || str_contains($normalized, 'insert')
            || str_contains($normalized, 'जोड़ें')
            || str_contains($normalized, 'नया')
            || str_contains($normalized, 'प्रविष्टि')
        ) {
            return 'create';
        }

        if (
            str_contains($normalized, 'publish')
            || str_contains($normalized, 'release')
            || str_contains($normalized, 'activate')
            || str_contains($normalized, 'live')
            || str_contains($normalized, 'प्रकाशित')
            || str_contains($normalized, 'सक्रिय')
            || str_contains($normalized, 'जारी')
        ) {
            return 'publish';
        }

        if (
            str_contains($normalized, 'view')
            || str_contains($normalized, 'see')
            || str_contains($normalized, 'show')
            || str_contains($normalized, 'check')
            || str_contains($normalized, 'list')
            || str_contains($normalized, 'status')
            || str_contains($normalized, 'search')
            || str_contains($normalized, 'देखें')
            || str_contains($normalized, 'सूची')
            || str_contains($normalized, 'स्थिति')
            || str_contains($normalized, 'खोज')
            || str_contains($normalized, 'filter')
        ) {
            return 'view';
        }

        return 'none';
    }

    /**
     * @return Collection<int, string>
     */
    private function queryTerms(string $query): Collection
    {
        $normalizedQuery = $this->normalizeForKeywords($query);

        $terms = collect(preg_split('/\s+/u', $normalizedQuery))
            ->filter(fn (?string $term) => $term && mb_strlen($term) >= 2)
            ->reject(fn (string $term) => in_array($term, $this->stopwords(), true))
            ->values();

        return $terms
            ->merge($this->expandedTerms($normalizedQuery))
            ->unique()
            ->values();
    }

    /**
     * @return array<int, string>
     */
    private function extractKeywords(string $text, array $extra = []): array
    {
        $normalized = $this->normalizeForKeywords($text);
        $tokens = collect(preg_split('/\s+/u', $normalized))
            ->filter(fn (?string $token) => $token && mb_strlen($token) >= 2)
            ->reject(fn (string $token) => in_array($token, $this->stopwords(), true));

        $extraTokens = collect($extra)
            ->map(fn ($item) => $this->normalizeForKeywords((string) $item))
            ->flatMap(fn (string $line) => preg_split('/\s+/u', $line) ?: [])
            ->filter(fn (?string $token) => $token && mb_strlen($token) >= 2)
            ->reject(fn (string $token) => in_array($token, $this->stopwords(), true));

        return $tokens
            ->merge($extraTokens)
            ->unique()
            ->take(32)
            ->values()
            ->all();
    }

    private function normalizeForKeywords(string $text): string
    {
        $normalized = mb_strtolower($text);
        $normalized = preg_replace('/[^\p{L}\p{M}\p{N}\s]/u', ' ', $normalized) ?? '';

        return trim((string) preg_replace('/\s+/u', ' ', $normalized));
    }

    /**
     * @return string[]
     */
    private function expandedTerms(string $normalizedQuery): array
    {
        $expanded = [];

        if (str_contains($normalizedQuery, 'login') || str_contains($normalizedQuery, 'signin')) {
            $expanded = array_merge($expanded, ['login', 'otp', 'password', 'लॉगिन', 'पासवर्ड', 'लिंक']);
        }

        if (str_contains($normalizedQuery, 'otp')) {
            $expanded = array_merge($expanded, ['otp', 'सत्यापन', 'verify', 'verification']);
        }

        if (str_contains($normalizedQuery, 'search') || str_contains($normalizedQuery, 'find')) {
            $expanded = array_merge($expanded, ['search', 'entries', 'खोज']);
        }

        if (str_contains($normalizedQuery, 'suchi') || str_contains($normalizedQuery, 'soochi')) {
            $expanded = array_merge($expanded, ['सूची', 'entries', 'list']);
        }

        if (str_contains($normalizedQuery, 'aavedan')
            || str_contains($normalizedQuery, 'avedan')
            || str_contains($normalizedQuery, 'application')) {
            $expanded = array_merge($expanded, ['आवेदन', 'आवेदक', 'application', 'entries', 'सूची']);
        }

        if (str_contains($normalizedQuery, 'download') || str_contains($normalizedQuery, 'manual')) {
            $expanded = array_merge($expanded, ['download', 'manual', 'guide', 'डाउनलोड', 'मार्गदर्शिका']);
        }

        if (str_contains($normalizedQuery, 'contact')) {
            $expanded = array_merge($expanded, ['contact', 'phone', 'email', 'संपर्क']);
        }

        if (str_contains($normalizedQuery, 'merit') || str_contains($normalizedQuery, 'ranking') || str_contains($normalizedQuery, 'rank')) {
            $expanded = array_merge($expanded, ['merit', 'ranking', 'rank', 'मेरिट', 'सूची', 'रैंक']);
        }

        if (str_contains($normalizedQuery, 'complaint')
            || str_contains($normalizedQuery, 'help')
            || str_contains($normalizedQuery, 'support')
            || str_contains($normalizedQuery, 'suggestion')
            || str_contains($normalizedQuery, 'feedback')) {
            $expanded = array_merge(
                $expanded,
                ['complaint', 'feedback', 'help', 'support', 'शिकायत', 'सहायता', 'सुझाव', 'प्रतिक्रिया']
            );
        }

        if (str_contains($normalizedQuery, 'objection') || str_contains($normalizedQuery, 'claim')) {
            $expanded = array_merge($expanded, ['objection', 'claim', 'आपत्ति', 'दावा']);
        }

        if (str_contains($normalizedQuery, 'excel') || str_contains($normalizedQuery, 'csv')) {
            $expanded = array_merge($expanded, ['excel', 'csv', 'download', 'डाउनलोड']);
        }

        return array_values(array_unique($expanded));
    }

    /**
     * @return string[]
     */
    private function stopwords(): array
    {
        return [
            'how', 'may', 'the', 'this', 'that', 'with', 'from', 'for', 'what', 'where',
            'kaise', 'kya', 'hai', 'hoga', 'kar', 'kare', 'karen', 'karo', 'portal', 'please',
            'mujhe', 'mera', 'meri', 'mere', 'mein', 'main', 'iska', 'iske', 'apna', 'ki', 'ke', 'ko',
            'aur', 'ya', 'mein', 'me', 'se', 'hai', 'hain', 'list', 'dekhen',
        ];
    }
}
