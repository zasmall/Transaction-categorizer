# Architecture

## Data model

### Tenancy and setup
- **clients**: `id, name, slug, fiscal_year_start, settings (json)`
- **client_user**: `client_id, user_id, role` — one bookkeeper can manage many clients
- **accounts** (chart of accounts): `id, client_id, code, name, type (asset|liability|equity|income|expense), parent_id, qbo_name, is_active`
- **bank_accounts**: `id, client_id, name, institution, last4, ledger_account_id → accounts, import_profile_id`
- **import_profiles**: `id, client_id (nullable = system default), parser_key, column_map (json), date_format, amount_convention (signed|debit_credit_columns|inverted), delimiter, has_header`

### Import and transactions
- **imports**: `id, client_id, bank_account_id, user_id, original_filename, stored_path, file_hash, status, total_rows, imported_rows, duplicate_rows, failed_rows, error, started_at, finished_at`
- **import_rows**: `id, import_id, row_number, raw (json), status, error` — staging table; keeps original data for audit/reprocessing
- **transactions**: `id, client_id, bank_account_id, import_id, import_row_id, posted_on (date), amount_cents (signed bigint), description_raw, payee_normalized, memo, fingerprint, account_id (nullable), categorization_status (uncategorized|suggested|approved)`
  - Unique index on `(bank_account_id, fingerprint)`

### Categorization
- **categorization_rules**: `id, client_id, name, priority, match_field (payee|description|memo), operator (contains|starts_with|equals|regex), pattern, direction (inflow|outflow|any), amount_min_cents, amount_max_cents, account_id, source (manual|learned), hits_count, last_matched_at, is_active`
- **categorizations**: `id, transaction_id, account_id, method (rule|ai|manual), rule_id, confidence, ai_reason, model, user_id, is_current, created_at` — append-only audit trail

### Key decisions
- **Fingerprint** = hash of `posted_on + amount_cents + normalized description + occurrence index`. The occurrence index distinguishes legitimate same-day duplicates (two identical coffees) from re-imported rows.
- **Categorization history** is a separate table so any transaction can answer "why is this in Meals?"

## Import pipeline

```
Upload ─► ParseFile ─► NormalizeRows ─► PersistAndDedupe ─► ApplyRules ─► AiCategorize (Bus::batch) ─► Finalize
```

Each stage is a queued job on the `imports` queue; the AI batch runs on the `ai` queue. Both run on Redis and are supervised by Laravel Horizon (`config/horizon.php` defines one supervisor per queue), which gives a live view of throughput, runtimes, batches and failed jobs. Jobs are tagged `import:{id}` and `client:{id}`.

Import status: `pending → parsing → normalizing → categorizing → completed | completed_with_errors | failed`

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
