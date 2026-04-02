# Chatbot Trail Workflow (User + Developer Standard)

Last updated: 2026-03-31 (tone-engine update)

## 1. Project Goal
This project is a **manual-grounded chatbot** where:
1. User manual document (DOCX/TXT/MD) is read from `public/chatbot_data_docs`.
2. Data is indexed in DB.
3. Answers are generated from **indexed manual + helpful historical responses**.
4. **No external AI model is required at response time**.

## 2. Runtime Architecture
1. UI: `resources/views/welcome.blade.php`.
2. Routes:
   - `POST /chat/stream` for streaming chatbot response.
   - `POST /chat/feedback` for `Helpful: Yes/No`.
   - `GET /chat/suggestions` for dynamic quick questions.
3. Controller: `app/Http/Controllers/ChatController.php`.
4. Knowledge indexing/search: `app/Services/ManualKnowledgeService.php`.
5. Models:
   - `app/Models/ManualChunk.php`
   - `app/Models/ChatResponseMemory.php`
6. Tables:
   - `manual_chunks`
   - `chat_response_memories`
7. Indexing mode: DOCX paragraph parsing + metadata-first ranking.

## 3. User Workflow
1. User opens chatbot page (`/`).
2. User sees dynamic quick-question chips (common + context based).
3. User can:
   - type custom question, or
   - click any quick/related question chip.
4. System answers in real-time stream.
5. With each answer, related next questions are shown as clickable chips.
6. User marks response `Yes` or `No` (helpful feedback).
7. Helpful responses are reused for similar future questions.

## 4. Developer Setup Workflow
1. Install dependencies:
```bash
composer install
npm install
```
2. Configure `.env` DB:
```env
APP_ENV=local
DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=chatbot_trail
DB_USERNAME=...
DB_PASSWORD=...
```
3. Place manual DOCX:
`public/chatbot_data_docs/AWW_CompleteManual.docx`
4. Run migrations:
```bash
php artisan migrate
```
5. Start app:
```bash
php artisan serve
```

## 5. Chat Response Flow (`/chat/stream`)
1. Validate messages payload.
2. Extract latest user query.
3. Ensure manual indexed (`ensureIndexed`).
4. Retrieve top manual chunks (metadata keyword match first, then content relevance).
5. Retrieve top helpful historical responses (similar query matching).
6. Build final answer:
   - manual only, or
   - history only, or
   - hybrid (manual + history).
7. Add dynamic related questions for current query/topic.
8. Detect tone preset from user query (`formal | friendly | concise | detailed`).
9. Build response with intent + tone aware formatting (no AI model call).
10. Save generated response in `chat_response_memories` with `helpful = null`.
11. Stream SSE events:
   - `stream_start`
   - `response_meta` (contains `response_id`, `source`, `related_questions`, `tone`)
   - `text_delta`
   - `stream_end`
   - `[DONE]`

## 6. Feedback Flow (`/chat/feedback`)
1. Frontend sends:
   - `response_id`
   - `helpful: true|false`
2. Backend updates that row in `chat_response_memories`.
3. Corrupted/unclear responses are auto-blocked from being marked helpful.
4. Future similar questions use only clean `helpful = true` rows as historical knowledge.

## 7. Dynamic Question Suggestions
1. Suggestion bank contains high-frequency user intents:
   - login
   - advertisement/post create/update
   - gram/gram panchayat master
   - application/merit/support
2. Topic detection uses user query text (and chunk context fallback).
3. Suggestions are shuffled by seed (time + query), so list is dynamic and not static.
4. Same suggestion engine powers:
   - top quick-question chips
   - per-answer related-question chips
5. Suggestions first prefer indexed `candidate_questions` from matched chunks, then fallback to curated bank.

## 8. Manual Indexing Logic
1. Find first supported document in `public/chatbot_data_docs` (priority: DOCX -> TXT -> MD).
2. Compute document SHA-256 hash.
3. If same hash already indexed, skip.
4. Else:
   - clear stale old index rows,
   - parse DOCX paragraphs from `word/document.xml`,
   - capture style metadata (style id/name, heading flag, fonts, sizes, bold/italic ratio),
   - extract metadata keywords per paragraph,
   - auto-generate `candidate_questions` (question forms where that chunk is likely answer),
   - store `question_keywords` for stronger question-tune matching,
   - build manageable chunks,
   - store `content + metadata` in `manual_chunks`.

## 9. Similarity + Ranking Logic
1. Query normalization (lowercase + cleanup).
2. Token extraction + domain expansion (login/otp/merit/complaint/etc).
3. Manual chunk score:
   - question match score (highest priority),
   - metadata keyword score (primary),
   - content score (secondary),
   - heading boost only when query signal exists.
4. Question-match gate:
   - if query is long, require higher overlap (3-4 keyword match),
   - if query is short, allow lower overlap (1-2) to avoid empty response.
5. Action gate (`create/update/publish/view`) is enforced for sensitive intents (advertisement/post/master/etc):
   - if user asks `update`, `create/add` lines are rejected.
   - if exact action step is not found, system returns clear not-found message instead of wrong action.
6. Historical memories are similarly scored.
7. Best snippets are selected from both sources.
8. If no confidence: return not found response.

## 10. Prompt-Engineering Style Humanization (Best Choice Implemented)
1. External “AI humanizer” tools are **not used**.
2. We use deterministic response shaping in backend:
   - detect query tone,
   - choose matching intro template,
   - vary style by random variant,
   - control detail level via snippet limits.
3. Cleanup layer removes noisy artifacts (path names, figure markers, OCR junk patterns) before final answer.
4. This gives natural, user-friendly variation while keeping answer fully manual-grounded and model-free.

## 11. Database Contract
`manual_chunks`
- `source_path`, `source_hash`, `chunk_order`, `content`, `metadata (json)`
  - metadata includes: `keywords`, `candidate_questions`, `question_keywords`, style/font signals.

`chat_response_memories`
- `question`, `normalized_question`, `answer`, `helpful`, `metadata`

## 12. Operations SOP
1. Replace DOCX/manual document in `public/chatbot_data_docs` when manual updates.
2. Re-index happens automatically on next query (hash change detection).
3. Encourage users to click `Yes/No` to improve historical ranking quality.

## 13. Quick Verification Checklist
1. `php artisan route:list --name=chat.` shows both routes.
2. `manual_chunks` and `chat_response_memories` tables exist.
3. First chat creates memory row with `helpful = null`.
4. Clicking `Yes/No` updates that row.
5. Similar later query uses helpful historical response + manual chunks.
