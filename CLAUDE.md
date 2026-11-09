# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Project

Laravel package that generates Eloquent models + migrations from DBML files. Published as `egyjs/dbml-to-laravel` on Packagist. Ships two Artisan commands registered by `DbmlToLaravelServiceProvider`:

- `dbml:generate {file} {--force} {--dry-run} {--json}` (legacy alias `generate:dbml`) — full generation + writes `.dbml-sync.json` snapshot next to the DBML file.
- `dbml:sync {file} {--force} {--dry-run} {--json}` — diffs DBML against the snapshot, emits create/alter/drop migrations, patches models inside `@dbml-sync` markers.

Both use `src/Commands/Concerns/ReportsResults.php` for `--dry-run`, the `--json` report (`success`, `dry_run`, `written`, `skipped`, `errors`) and exit codes (any reported error → `FAILURE`). `canPrompt()` treats a non-TTY stdin as non-interactive because Symfony only checks `posix_isatty` (missing on Windows) — `dbml:sync` must fail with "re-run with --force" instead of blocking on `confirm()`.

## Agent-facing files

These are read by AI tools and must stay accurate — `tests/Unit/AgentDocsTest.php` checks that DBML examples parse and that every documented command, `--option` and `@dbml-sync` marker exists:

- `README.md`, `llms.txt`, `context7.json` (Context7 indexing + rules)
- `resources/boost/guidelines/core.blade.php`, `resources/boost/skills/dbml-to-laravel/SKILL.md` (Laravel Boost auto-discovers these paths)

When adding a command option or changing behavior, update all of them.

## Commands

```bash
composer install              # install PHP deps (auto-runs `testbench package:discover`)
composer test                 # run Pest test suite
composer test-coverage        # run tests with coverage
composer analyse              # PHPStan via larastan (phpstan.neon.dist: level 0, src + tests)
composer format               # Laravel Pint (PSR-12)

vendor/bin/pest --filter="<pattern>"   # run a single test
vendor/bin/pest tests/Feature/GenerateFromDbmlCommandTest.php

npm install && npm run build-parser    # rebuild bundled parser (contributors only)
```

Runtime needs Node.js 18+ on PATH — PHP shells out to `node` via `symfony/process`.

## Architecture

Two-stage pipeline: **Node parser → PHP schema model → stub-based code generation**.

### Stage 1 — DBML parsing (Node)
`src/Parsing/NodeDbmlParser.php` runs `node bin/parse-dbml.runtime.cjs <file>` (falls back to `bin/parse-dbml.js` if compiled artifact missing). The Node script uses `@dbml/core`'s `Parser` and emits a normalized JSON payload on stdout: `{ tables, enums, refs }`. PHP decodes JSON, throws `RuntimeException` on parser stderr or malformed output.

`bin/parse-dbml.runtime.cjs` is the **committed esbuild bundle** — consumers never run `npm install`. Edit `bin/parse-dbml.js` then `npm run build-parser` and commit both files.

### Stage 2 — Schema model (PHP)
`src/Parsing/Dbml/SchemaFactory::fromArray()` builds an object graph from the JSON: `Schema` → `Table[]` → `Column[]` (each with `ColumnType`, `ColumnDefaultValue`, `ColumnReference[]`), plus `IndexDefinition[]` per table and `EnumDefinition[]` on schema.

Foreign keys are NOT stored on tables — they're attached to the **referencing** `Column` via `addReference()`. `determineDirection()` reads DBML endpoint `relation` (`'*'` vs `'1'`) to decide which side is referencing vs referenced; refs with unsupported relations (e.g. many-to-many `*:*`) are silently dropped.

Column lookup uses `strtolower(schema.table.column)` keys — case-insensitive matching against DBML.

### Stage 3 — Code generation
`src/Commands/GenerateFromDbml.php` orchestrates generation; column/index code lives in `src/Generation/ColumnDefinitionBuilder.php`, model code in `src/Generation/ModelContentBuilder.php` (shared with `dbml:sync`). Key behaviors:

- **Stub resolution**: checks `base_path('stubs/dbml-to-laravel/<name>')` first (published stubs override package defaults in `stubs/`).
- **Migration ordering**: uses `$migrationCounter` (incremented per table) appended to today's date — `Y_m_d_000000`, `Y_m_d_000001`, etc. — so foreign-key dependencies preserve DBML table order. Without `--force`, existing `*_create_<table>_table.php` files cause skip.
- **Foreign keys**: when referencing column type is `bigint unsigned` OR empty, uses `foreignId()->constrained()` (Laravel sugar). Otherwise emits explicit `$table->foreign()->references()->on()` after the column line. `onDelete`/`onUpdate` map only `cascade|restrict|set null` → other actions silently dropped.
- **Nullability**: columns without DBML `not null` get `->nullable()`; `Column::isNull()` is always false for primary keys.
- **Auto-increment PK** detection (`isAutoIncrementingPrimaryKey`): PK + autoIncrement → `increments`/`bigIncrements`/`smallIncrements`. Non-auto PK → suffix `->primary()`.
- **Forbidden model names**: PHP reserved words in `FORBIDDEN_MODEL_NAMES` cause table to be skipped with error and the command to exit 1 (no model AND no migration generated for it — note: counter still consistent because both `generateModel`/`generateMigration` return false independently).
- **Relations**: `belongsTo` from columns with refs; `hasMany` discovered by scanning all tables for refs pointing back. Many-to-many is not generated.
- **Casts**: `mapCastType()` filters out `string` and `integer` (Laravel defaults) so they don't appear in `$casts`.
- **Table property**: only emitted in model when DBML table name diverges from `Str::snake(Str::plural($modelName))`.

### Stubs
`stubs/model.stub` and `stubs/migration.stub` use placeholders: `{{ modelName }}`, `{{ tableProperty }}`, `{{ fillable }}`, `{{ casts }}`, `{{ relations }}`, `{{ tableName }}`, `{{ columns }}`, `{{ indexes }}`. String replace only — no template engine. Indentation inside fillable/casts is hardcoded as 2 tabs in `generateModelContent`.

## Tests

Pest 3/2/1 compatible (multi-version constraint). `tests/TestCase.php` extends `Orchestra\Testbench\TestCase` and registers the service provider. Fixtures live in `tests/Fixtures/*.dbml`. The feature test invokes the Artisan command and asserts generated files.

The `post-autoload-dump` composer hook runs `testbench package:discover` — if dev install fails on a fresh clone, that's why.

## Compatibility matrix

Supports PHP 8.2–8.4 and Laravel 8–13 in one branch via wide composer constraints (code uses `readonly` classes, so PHP 8.2 is the floor). When adding code: avoid PHP 8.3+ syntax (typed class constants, `#[\Override]`) unless gated, and avoid Laravel APIs added after 8.x without checking — package targets the widest range still in `composer.json`.
