# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Project

Laravel package that generates Eloquent models + migrations from DBML files. Published as `egyjs/dbml-to-laravel` on Packagist. Ships single Artisan command `generate:dbml {file} {--force}` registered by `DbmlToLaravelServiceProvider`.

## Commands

```bash
composer install              # install PHP deps (auto-runs `testbench package:discover`)
composer test                 # run Pest test suite
composer test-coverage        # run tests with coverage
composer analyse              # PHPStan via larastan (no phpstan.neon committed — uses defaults)
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
`src/Commands/GenerateFromDbml.php` is monolithic — handles both model + migration generation. Key behaviors:

- **Stub resolution**: checks `base_path('stubs/dbml-to-laravel/<name>')` first (published stubs override package defaults in `stubs/`).
- **Migration ordering**: uses `$migrationCounter` (incremented per table) appended to today's date — `Y_m_d_000000`, `Y_m_d_000001`, etc. — so foreign-key dependencies preserve DBML table order. Without `--force`, existing `*_create_<table>_table.php` files cause skip.
- **Foreign keys**: when referencing column type is `bigint unsigned` OR empty, uses `foreignId()->constrained()` (Laravel sugar). Otherwise emits explicit `$table->foreign()->references()->on()` after the column line. `onDelete`/`onUpdate` map only `cascade|restrict|set null` → other actions silently dropped.
- **Auto-increment PK** detection (`isAutoIncrementingPrimaryKey`): PK + autoIncrement → `increments`/`bigIncrements`/`smallIncrements`. Non-auto PK → suffix `->primary()`.
- **Forbidden model names**: PHP reserved words in `FORBIDDEN_MODEL_NAMES` cause table to be skipped with error (no model AND no migration generated for it — note: counter still consistent because both `generateModel`/`generateMigration` return false independently).
- **Relations**: `belongsTo` from columns with refs; `hasMany` discovered by scanning all tables for refs pointing back. Many-to-many is not generated.
- **Casts**: `mapCastType()` filters out `string` and `integer` (Laravel defaults) so they don't appear in `$casts`.
- **Table property**: only emitted in model when DBML table name diverges from `Str::snake(Str::plural($modelName))`.

### Stubs
`stubs/model.stub` and `stubs/migration.stub` use placeholders: `{{ modelName }}`, `{{ tableProperty }}`, `{{ fillable }}`, `{{ casts }}`, `{{ relations }}`, `{{ tableName }}`, `{{ columns }}`, `{{ indexes }}`. String replace only — no template engine. Indentation inside fillable/casts is hardcoded as 2 tabs in `generateModelContent`.

## Tests

Pest 3/2/1 compatible (multi-version constraint). `tests/TestCase.php` extends `Orchestra\Testbench\TestCase` and registers the service provider. Fixtures live in `tests/Fixtures/*.dbml`. The feature test invokes the Artisan command and asserts generated files.

The `post-autoload-dump` composer hook runs `testbench package:discover` — if dev install fails on a fresh clone, that's why.

## Compatibility matrix

Supports PHP 8.0–8.4 and Laravel 8–13 in one branch via wide composer constraints. When adding code: avoid PHP 8.1+ syntax (enums, readonly, never) unless gated, and avoid Laravel APIs added after 8.x without checking — package targets the widest range still in `composer.json`.
