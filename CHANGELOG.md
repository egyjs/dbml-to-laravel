# Changelog

All notable changes to `dbml-to-laravel` will be documented in this file.

## v2.1.00

### Added
- `dbml:sync` command for incremental schema syncing. Compares the current DBML file against the snapshot from the last `dbml:generate` run and generates only the changes: alter migrations for modified tables, create migrations for new tables, drop migrations for removed tables, and patches existing Eloquent models within `@dbml-sync` markers (`src/Commands/SyncDbml.php`).
- Schema diffing engine (`src/Parsing/Dbml/SchemaDiffer.php`, `SchemaDiff`, `TableDiff`, `ColumnPair`) that detects added/dropped/modified columns, indexes, and foreign keys between two Schema objects.
- `JsonSnapshotStore` (`src/Parsing/Dbml/JsonSnapshotStore.php`) — persists the parser payload as `.dbml-sync.json` adjacent to the DBML file so `dbml:sync` can diff against the previous state. `generate:dbml` now writes this snapshot automatically.
- `AlterMigrationGenerator` (`src/Generation/AlterMigrationGenerator.php`) — builds `Schema::table()` migrations from a `TableDiff`, including add/drop/modify columns, indexes, and foreign keys with reversible `up()`/`down()` methods.
- `ModelPatcher` (`src/Generation/ModelPatcher.php`) — patches existing model files within `// @dbml-sync:fillable`, `// @dbml-sync:casts`, `// @dbml-sync:relations`, and `// @dbml-sync:table-property` marker blocks, leaving hand-written code outside the markers untouched.
- `alter-migration.stub` for alter migration output.
- Model stub updated with `@dbml-sync` markers around fillable, casts, relations, and table-property sections.
- Test fixtures `sync-base.dbml` and `sync-modified.dbml` for sync integration tests.
- Feature tests for `dbml:sync` (6 tests) and unit tests for `SchemaDiffer`, `JsonSnapshotStore`, `AlterMigrationGenerator`, `ModelPatcher`, `ColumnDefinitionBuilder`, and `ModelContentBuilder`.

### Changed
- `generate:dbml` command renamed to `dbml:generate`. The old name is preserved as a legacy alias via `$aliases` on the command class.
- `GenerateFromDbml` refactored to delegate to `ColumnDefinitionBuilder` and `ModelContentBuilder` (extracted for DRY sharing with `dbml:sync`).
- `FORBIDDEN_MODEL_NAMES` changed from `private` to `public` so `SyncDbml` can reference it.
- `NodeDbmlParser` now exposes `getLastPayload()` so the snapshot store can persist the raw parser output.

## [1.0.0] - Previous release

### Added
- New `generate:dbml` command pipeline that uses the bundled Node-based parser (`bin/parse-dbml.js`) and rich schema objects (`src/Parsing/Dbml/*`) to generate models, migrations, enums, indexes, and relationships end-to-end (`src/Commands/GenerateFromDbml.php`).
- Bundled parser runtime plus npm tooling (`package.json`, `package-lock.json`, `bin/parse-dbml.runtime.cjs`) with contributor instructions for rebuilding.
- Feature tests and fixtures that cover successful generation and error handling (`tests/Feature/GenerateFromDbmlCommandTest.php`, `tests/Fixtures/simple.dbml`).

### Changed
- Model and migration stubs now expose additional placeholders so generated migrations include column definitions, indexes, and relationship hints (`stubs/*.stub`).
- Documentation and contribution guides call out the Node.js requirement and how to refresh the parser bundle (`README.md`, `CONTRIBUTING.md`).
- CI workflows install the new prerequisites and run the updated test suite (`.github/workflows/*.yml`).


