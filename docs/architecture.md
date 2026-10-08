# Architecture

## Data model

### Tenancy and setup

- **clients**: `id, name, slug, fiscal_year_start, settings (json)`
- **client_user**: `client_id, user_id, role` — one bookkeeper can manage many clients
- **accounts** (chart of accounts): `id, client_id, code, name, type (asset|liability|equity|income|expense), parent_id, qbo_name, is_active`
- **bank_accounts**: `id, client_id, name, institution, last4, ledger_account_id → accounts, import_profile_id`
- **import_profiles**: `id, client_id (nullable = system default), parser_key, column_map (json), date_format, amount_convention (signed|debit_credit_columns|inverted), delimiter, has_header`

### Import and transactions

- **imports**: `id, client_id, bank_account_id, import_profile_id, user_id, original_filename, stored_path, file_hash, status, total_rows, imported_rows, duplicate_rows, failed_rows, error, started_at, finished_at`
- **import_rows**: `id, import_id, row_number, raw (json), normalized (json), status (pending|normalized|imported|duplicate|failed), error` — staging table; keeps original data for audit/reprocessing. `normalized` lets each stage run (and retry) independently.
- **transactions**: `id, client_id, bank_account_id, import_id, import_row_id, posted_on (date), amount_cents (signed bigint), description_raw, payee_normalized, memo, fingerprint, account_id (nullable), categorization_status (uncategorized|suggested|approved), exported_at (nullable)`
    - Unique index on `(bank_account_id, fingerprint)`

### Categorization

- **categorization_rules**: `id, client_id, name, priority, match_field (payee|description|memo), operator (contains|starts_with|equals|regex), pattern, direction (inflow|outflow|any), amount_min_cents, amount_max_cents, account_id, source (manual|learned), hits_count, last_matched_at, is_active`
- **categorizations**: `id, transaction_id, account_id, method (rule|ai|manual), rule_id, rule_name, confidence, ai_reason, model, user_id, is_current, created_at` — append-only audit trail (`rule_name` is copied so history still reads well after a rule is deleted)

### Key decisions

- **Fingerprint** = hash of `posted_on + amount_cents + normalized description + occurrence index`. The occurrence index distinguishes legitimate same-day duplicates (two identical coffees) from re-imported rows.
- **Categorization history** is a separate table so any transaction can answer "why is this in Meals?"

## Import pipeline

```
Upload ─► ParseFile ─► NormalizeRows ─► PersistAndDedupe ─► ApplyRules ─► AiCategorize (Bus::batch) ─► Finalize
```

Each stage is a queued job on the `imports` queue; the AI batch runs on the `ai` queue. Both run on Redis and are supervised by Laravel Horizon (`config/horizon.php` defines one supervisor per queue), which gives a live view of throughput, runtimes, batches and failed jobs. Jobs are tagged `import:{id}` and `client:{id}`.

Import status: `pending → parsing → normalizing → persisting → categorizing → suggesting → completed | completed_with_errors | failed`

1. **Upload (controller)** — validate, store, hash file. Warn if the same file hash was already imported for that bank account. Create `Import` (pending), dispatch `Bus::chain`.
2. **ParseFile** — resolve parser from the import profile; stream rows into `import_rows` in chunks.
    ```php
    interface StatementParser {
        /** @return iterable<RawRow> */
        public function parse(string $path, ImportProfile $profile): iterable;
    }
    ```
    Start with `CsvParser` (driven by `column_map`); `OfxParser` later.
3. **NormalizeRows** — map to `NormalizedTransaction` DTO (amount convention, date format). Payee normalizer pipeline: strip processor prefixes (`SQ *`, `TST*`, `POS`), store numbers, trailing IDs; title-case. Bad rows → `failed` with reason.
4. **PersistAndDedupe** — compute fingerprints; chunked DB transactions with `insertOrIgnore` against the unique index; count duplicates/failures.
5. **ApplyRules** — `RuleEngine` evaluates active rules by priority, first match wins. Writes categorization (`method=rule`, approved), increments hit count.
6. **AiCategorize** — chunk leftovers (~25–50) into a `Bus::batch`. Prism call includes chart of accounts (codes + names), a few recent approved examples as few-shot context, and a structured-output schema `{transaction_id, account_code, confidence, reason}`. Validate account codes; invalid → stays uncategorized. Store as `suggested`. Use `RateLimited` + `ThrottlesExceptions` middleware with backoff; record model and token usage.
7. **Finalize** — update counts and status; broadcast `ImportCompleted` (Reverb) or poll.

## Review loop

- Queue of `suggested` + `uncategorized`, lowest confidence first; bulk approve.
- On manual correction, offer "Create rule from this?" prefilled from `payee_normalized`.
- When the same payee is approved to the same account 3+ times, suggest a learned rule.
- Over time rules handle more and AI handles less — lower API cost.

## Showcase extras

- Seeded demo with fake data (no signup needed)
- README with architecture diagram and design rationale
- Short screen recording: messy CSV → categorized

## Import pipeline: implementation notes (Milestone 2)

- **Where things live**: `App\Imports\Parsing` (`StatementParser`, `CsvParser`, `ParserRegistry`, `RawRow`), `App\Imports\Normalizing` (`TransactionNormalizer`, `PayeeNormalizer`, `NormalizedTransaction`), `App\Imports\Fingerprinter`, `App\Support\Money`, jobs in `App\Jobs\Imports`, orchestration in `App\Services\ImportService`.
- **Two kinds of failure**: `InvalidStatementFile` (wrong columns, empty file) fails the whole import with a user-facing message; `InvalidRow` marks one row failed and the import carries on. Anything unexpected is thrown, shows up as a failed job in Horizon, and the chain's `catch` marks the import failed.
- **Stopping the chain**: the `SkipIfImportFailed` job middleware skips later stages once an import is marked failed, so expected failures don't pile up as Horizon errors.
- **Retry safety**: parsing is skipped if a previous attempt finished it (`total_rows > 0`), otherwise partial rows are cleared first; normalizing only touches `pending` rows; persisting re-fingerprints every normalized/imported/duplicate row in file order (occurrence indexes depend on it) but only inserts `normalized` ones, with `insertOrIgnore` against the unique index.
- **Fingerprints** use the raw description (lowercased, whitespace-collapsed), not the cleaned payee, so improving payee rules never changes existing fingerprints.
- **Money** is parsed from the string (`"1,234.56"`, `"(45.00)"`, `"-$5"`) straight to integer cents; floats are never involved.
- **Payee cleanup** strips processor prefixes, store/reference numbers, phone numbers, ACH addenda and a trailing state code. City names stay, so rules should use `contains` / `starts_with`.
- **Duplicate file warning**: uploading a file whose SHA-256 matches an earlier (non-failed) import into the same bank account is rejected unless "Import anyway" is ticked. Row-level dedupe would skip everything regardless; this just catches the likely mistake.

## Rules and categorization: implementation notes (Milestone 3)

- **Single writer**: `CategorizationService` is the only code that writes categorizations. Each decision appends a row, clears `is_current` on the previous one, and updates the transaction's `account_id` and `categorization_status`, all in one DB transaction. It refuses an account belonging to another client.
- **Precedence**: people beat rules, and rules beat AI. Rules only touch `uncategorized` and `suggested` transactions, never `approved` ones. A manual categorization always wins.
- **Matching** (`CategorizationRule::matches`, `App\Categorization\RuleEngine`): case-insensitive `contains` / `starts_with` / `equals`, or a regex wrapped as `~pattern~iu` (validated on save; an invalid one never matches). Optional direction (money in/out) and inclusive bounds on the **absolute** amount. Rules run by `priority` ascending, then `id`; first match wins. Rules whose account is inactive are skipped.
- **Pipeline**: `ApplyCategorizationRules` runs after persisting (status `categorizing`); `FinalizeImport` records `categorized_rows`.
- **Rule hits**: `hits_count` / `last_matched_at` are bumped once per rule per run with `incrementEach`, not per transaction.
- **Rule suggestions**: after a manual categorization, if no existing rule would have chosen the same account, the response flashes `ruleSuggestion` (payee, account, and how many other uncategorized transactions a "payee contains" rule would catch). "Create rule" opens the rule form prefilled from it.
- **Scale note**: "apply rules now" runs in the request. Fine at demo scale; for large backlogs it would move onto the queue like the import stages.

## AI fallback: implementation notes (Milestone 4)

- **Where it lives**: `App\Categorization\Ai` — `AiCategorizer` interface, `CategorizationPrompt` (shared prompt, schema and parsing), `PrismClaudeCategorizer`, `SdkClaudeCategorizer` (official Anthropic PHP SDK), `DemoCategorizer`, `FewShotExamples`; jobs `QueueAiSuggestions` and `SuggestCategoriesForChunk`; config in `config/categorization.php`.
- **Drivers** (`AI_CATEGORIZER`): `prism` and `sdk` call Claude via Prism or the official SDK (both need `ANTHROPIC_API_KEY`, both send the identical request); `demo` (the default) matches keywords to account names with no API calls, and every suggestion it makes is labelled "Demo suggestion" in the UI so it's never mistaken for AI; `disabled` skips the stage. Tests run with `disabled` and opt in.
- **Batch inside the chain**: rules leave some transactions uncategorized, and only then is it known what to send. `QueueAiSuggestions` chunks those ids and calls `prependToChain(Bus::batch(...))`, so the batch runs on the `ai` queue and `FinalizeImport` is dispatched from the batch's `finally` callback. The batch `allowFailures()`: a chunk that fails just leaves its transactions for manual review.
- **Request shape**: system prompt = instructions + the client's chart of accounts + up to 20 recent approved payee→code examples, marked `cache_control: ephemeral` (identical for every chunk of an import). User message = the chunk's transactions as JSON lines. Response is constrained with a JSON schema through Prism's native `output_config.format` strategy (no forced tool use, which current Claude models reject). No sampling parameters are sent.
- **Never trust the output**: suggestions for transaction ids outside the chunk or account codes not in the client's active chart are dropped; confidence is clamped to 0–100; the job re-checks status before sending, and `CategorizationService::suggest()` only touches `uncategorized` transactions. Suggestions are stored as `suggested` and are never auto-approved.
- **Rate limits and retries**: `RateLimited('ai-categorization')` (requests/minute shared across workers) plus `ThrottlesExceptions` for transient errors from either client (`SuggestCategoriesForChunk::isTransient()`: rate limits, overloads, 5xx, dropped connections); chunks keep retrying for 15 minutes (`retryUntil`), then fail into Horizon. The SDK runs with `maxRetries: 0` so the queue is the only retry layer; auth and bad-request errors fail immediately.
- **Cost visibility**: each import records `ai_model`, `ai_input_tokens`, `ai_output_tokens` and `ai_suggested_rows`.
- **Model**: `AI_MODEL` defaults to `claude-haiku-4-5`, the least expensive current model, chosen for a simple high-volume classification task. The SDK driver treats any stop reason other than `end_turn` (a refusal, or hitting `max_tokens`) as "no suggestions" for that chunk while still recording token usage. The Prism driver can't set effort or the server-side refusal `fallbacks` parameter; the SDK could add those if a larger model is used.

## Review, learned rules and export: implementation notes (Milestone 5)

- **Review queue** (`ReviewController`): uncategorized and suggested transactions, ordered by the current categorization's confidence with uncategorized first (`coalesce(confidence, -1)`), then date. Bulk approve accepts up to 200 ids, re-scopes them to the client and to `suggested`, and reports anything skipped.
- **Learned rules** (`App\Categorization\LearnedRuleSuggestions`): approved transactions grouped by `lower(payee_normalized)` and account. A payee qualifies with 3+ approvals to one account, no approvals to any other account, and no existing rule that already sends it there. Creating one makes a `source = learned`, payee-equals rule (the request is re-checked against the live suggestions) and runs rules over the backlog.
- **QuickBooks export** (`App\Exports\QuickBooksJournalCsv`): journal entry CSV (`Journal No, Journal Date, Account Name, Debits, Credits, Description, Memo`), two lines per approved transaction. Money out debits the category and credits the bank/card ledger account; money in does the reverse. Account names use `qbo_name` when set (for sub-accounts like `Cost of Goods Sold:Ingredients & Supplies`). Streamed with `cursor()`.
- **Export workflow**: preview, download and mark share one `ExportFilterRequest` (date range, bank account, include already-exported). Download is a side-effect-free GET; "Mark as exported" stamps `exported_at` afterwards, so a rejected file can be re-downloaded.
- **Demo data** (`DemoSeeder` + `database/seeders/statements/`): generated, deterministic statements. Seeding imports them synchronously with the demo categorizer (never a paid API), reviews and exports Northwind's January (including a correction that becomes a learned-rule suggestion), and leaves the rest in review.

## Receiving webhooks from Webhook Relay (Milestone 6)

- `POST /api/webhooks/relay` lives in `routes/api.php`, so it's stateless and has no CSRF check. It's protected by the `relay.signature` middleware from the `zasmall/relay-signature` package, which verifies `X-Relay-Signature` (HMAC-SHA256 over the raw body, with a 300-second timestamp tolerance) against `RELAY_WEBHOOK_SECRET`. During a rotation, set that to a comma-separated list to accept two secrets.
- `RelayWebhookController` validates the envelope (`id`, `type`, `data`) and `insertOrIgnore`s a `webhook_receipts` row. `event_id` is unique, so a redelivery (the relay is at-least-once) answers `200 {"duplicate": true}` and stores nothing.
- `data` is re-encoded from the raw body as objects, so `{}` stays `{}`, both when stored and when shown on the `/webhooks` page.
- Receipts aren't client-owned. They record what arrived; nothing acts on them yet. Turning `transaction.posted` events into real transactions (a "bank feed") would be the next step, and it would need a mapping from events to a client and bank account.
- The package is required through a Composer **path repository** (`../webhook-relay-service/packages/relay-signature`), so it only installs where both projects are checked out side by side.
