# Schema Sync / Incremental Migrations Design

GitHub Issue: #16
Date: 2026-05-16
Status: Approved

## Goal

Add `dbml:sync` Artisan command that compares an existing schema snapshot with an updated DBML file and generates incremental ALTER migrations + patches Eloquent models, moving the package from one-time scaffolding to long-term schema management.

## Architecture

Approach C: SchemaDiffer as reusable service + SnapshotStore + thin SyncDbml command. Existing `generate:dbml` stays untouched.

### New classes

| Class | Location | Responsibility |
|-------|----------|---------------|
| `SyncDbml` | `src/Commands/` | Artisan command `dbml:sync {file} {--force}` |
| `SchemaDiffer` | `src/Parsing/Dbml/` | Compare two Schema objects, produce SchemaDiff |
| `SchemaDiff` | `src/Parsing/Dbml/` | Value object: added/dropped/modified columns, indexes, FKs per table |
| `TableDiff` | `src/Parsing/Dbml/` | Per-table diff: added/dropped/modified columns, indexes, FKs |
| `ColumnPair` | `src/Parsing/Dbml/` | Old + new Column for modification tracking |
| `SnapshotStore` | `src/Parsing/Dbml/` | Interface: read/write snapshot |
| `JsonSnapshotStore` | `src/Parsing/Dbml/` | File-based snapshot (.dbml-sync.json) |
| `ModelPatcher` | `src/Generation/` | Patch model files within @dbml-sync markers |
| `AlterMigrationGenerator` | `src/Generation/` | Generate Schema::table migrations from TableDiff |

### DRY: Shared generation logic

`GenerateFromDbml` contains reusable logic that both `generate:dbml` and `dbml:sync` need. Currently all private methods — must extract before building sync.

**Extract to `src/Generation/` as standalone services:**

| Extracted class | Methods extracted from `GenerateFromDbml` | Used by |
|----------------|---------------------------------------------|---------|
| `ColumnDefinitionBuilder` | `buildColumnDefinition()`, `resolveColumnBaseDefinition()`, `formatDefaultValue()`, `formatForeignKeyActions()`, `mapForeignAction()`, `isAutoIncrementingPrimaryKey()` | `GenerateFromDbml`, `AlterMigrationGenerator` |
| `ModelContentBuilder` | `generateFillable()`, `generateCasts()`, `mapCastType()`, `generateTableProperty()`, `generateBelongsToRelations()`, `generateHasManyRelations()`, `parseRelations()`, `formatRelationBody()` | `GenerateFromDbml`, `ModelPatcher` |

**What stays in `GenerateFromDbml`:** Command orchestration (handle loop, file writing, stub resolution, migration naming, counter logic). It delegates to the two builders.

**What `AlterMigrationGenerator` reuses:** `ColumnDefinitionBuilder` for generating column lines in alter migrations (e.g., `$table->string('phone')` in `Schema::table`). Does NOT duplicate column type mappings, FK formatting, or default value formatting.

**What `ModelPatcher` reuses:** `ModelContentBuilder` for generating fresh fillable/casts/relations content inside `@dbml-sync` markers. Does NOT duplicate cast type maps, fillable filtering, or relation formatting.

**No duplication rule:** If any piece of column→migration or column→model mapping logic exists, it must live in one place. `GenerateFromDbml` is the consumer, not the owner.

### Data flow

```
schema.dbml → NodeDbmlParser → Schema (new)
                                  ↓
SnapshotStore.read() → Schema (old) → SchemaDiffer.diff() → SchemaDiff
                                  ↓                            ↓
                          SyncDbml command ←────────────────┘
                                  ↓
                    ┌──────────────┴───────────────┐
              Alter migrations              Model patches
           (Schema::table stub)         (@dbml-sync markers)
```

## SnapshotStore

### Interface

```php
interface SnapshotStore
{
    public function read(string $dbmlPath): ?Schema;
    public function write(string $dbmlPath, array $jsonPayload): void;
    public function exists(string $dbmlPath): bool;
}
```

### JsonSnapshotStore implementation

- Snapshot path: `{dirname(dbmlFile)}/.dbml-sync.json` (adjacent to DBML file)
- Content: raw JSON payload from NodeDbmlParser (pre-SchemaFactory)
- `read()` decodes JSON, feeds to `SchemaFactory::fromArray()` to rebuild Schema
- Version field in snapshot for future compatibility checks

### Snapshot format

```json
{
  "version": 1,
  "generated_at": "2026-05-16T10:00:00Z",
  "tables": [ ... ],
  "enums": { ... },
  "refs": [ ... ]
}
```

### generate:dbml modification

After successful generation, write snapshot via SnapshotStore. Single line addition in `GenerateFromDbml::handle()` after the generation loop. This seeds the snapshot for future sync runs.

## SchemaDiffer

### Matching strategy

- Tables matched by name (strtolower)
- Columns matched by name within matched tables
- Indexes matched by column composition (same columns = same index)
- FKs matched by (column name, referenced table, referenced column)

### Column modification detection

A column is "modified" when any of these differ between old and new:
- Type (name + args)
- Nullability
- Default value
- Unique flag
- Primary key flag
- Auto-increment flag

### Scope cut

**No column rename detection in v1.** Renames appear as drop + add. Rename heuristics are fragile; explicit hints or a `--rename` flag could be added later.

### SchemaDiff structure

```php
class SchemaDiff
{
    /** @var Table[] — tables in new but not old */
    public array $addedTables;
    /** @var string[] — table names in old but not new */
    public array $droppedTables;
    /** @var TableDiff[] — tables in both with changes */
    public array $modifiedTables;
}

class TableDiff
{
    public string $tableName;
    /** @var Column[] */
    public array $addedColumns;
    /** @var Column[] */
    public array $droppedColumns;
    /** @var ColumnPair[] */
    public array $modifiedColumns;
    /** @var IndexDefinition[] */
    public array $addedIndexes;
    /** @var IndexDefinition[] */
    public array $droppedIndexes;
    /** @var ColumnReference[] */
    public array $addedForeignKeys;
    /** @var ColumnReference[] */
    public array $droppedForeignKeys;
}

class ColumnPair
{
    public Column $old;
    public Column $new;
}
```

## Migration Generation from Diff

### Alter migration stub (stubs/alter-migration.stub)

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('{{ tableName }}', function (Blueprint $table) {
{{ upColumns }}
        });
    }

    public function down(): void
    {
        Schema::table('{{ tableName }}', function (Blueprint $table) {
{{ downColumns }}
        });
    }
};
```

### Per-change migration method mapping

| Change | up() | down() |
|--------|------|--------|
| Added column | `$table->string('name')` | `$table->dropColumn('name')` |
| Dropped column | `$table->dropColumn('name')` | recreate with original def |
| Modified column | `$table->string('name')->nullable()->change()` | revert to old def with `->change()` |
| Added index | `$table->index(['col'])` | `$table->dropIndex(['col'])` |
| Dropped index | `$table->dropIndex(['col'])` | `$table->index(['col'])` |
| Added FK | `$table->foreign('col')->references('id')->on('table')` | `$table->dropForeign(['col'])` |
| Dropped FK | `$table->dropForeign(['col'])` | `$table->foreign(...)->references(...)->on(...)` |

### Migration file naming

- Format: `{timestamp}_sync_{table}_{action}.php`
- Examples: `2026_05_16_000000_sync_users_add_columns.php`
- One migration per modified table
- `--force` overwrites existing sync migrations for same table

### New table handling

If `SchemaDiff::addedTables` is non-empty, generate `Schema::create` migrations using existing `GenerateFromDbml` column-building logic (delegated via shared method, not duplicated).

### Dropped table handling

Generate `Schema::dropIfExists` migration. Warn user — this is destructive. Prompt before write unless `--force`.

## Model Patching with @dbml-sync Markers

### Marker format

```php
// @dbml-sync:table-property
protected $table = 'custom_users';
// @enddbml-sync:table-property

// @dbml-sync:fillable
protected $fillable = [
    'name',
    'email',
];
// @enddbml-sync:fillable

// @dbml-sync:casts
protected $casts = [
    'is_active' => 'boolean',
];
// @enddbml-sync:casts

// @dbml-sync:relations
public function posts(): HasMany
{
    return $this->hasMany(Post::class);
}
// @enddbml-sync:relations
```

### ModelPatcher behavior

1. Read existing model file
2. Find marker pairs (`// @dbml-sync:{section}` ... `// @enddbml-sync:{section}`)
3. Replace content between markers with fresh generated code
4. If markers missing (pre-existing model without markers):
   - Insert markers + generated section before closing `}`
   - Warn: "Added @dbml-sync markers to User model. Review the generated sections."
5. If model doesn't exist yet: generate fresh with markers (delegate to existing model logic + add markers)

### Prompt-before-write safety net

- After computing patches, display diff summary
- Example: "3 changes to User model: added 'phone' to fillable, added cast 'phone' => 'string', added hasMany relation"
- Ask: "Apply these changes? [yes/no]"
- Skip prompt with `--force`
- Only write after confirmation

### Marker insertion order for unmarked models

1. `// @dbml-sync:table-property` (if needed)
2. `// @dbml-sync:fillable`
3. `// @dbml-sync:casts`
4. `// @dbml-sync:relations`

## Command Flow (dbml:sync)

```
1. Parse new DBML → Schema (via NodeDbmlParser)
2. SnapshotStore.read() → Schema (old) | null
3. If null: error "No snapshot found. Run generate:dbml first." Exit FAILURE.
4. SchemaDiffer.diff(old, new) → SchemaDiff
5. If SchemaDiff is empty: info "No changes detected." Exit SUCCESS.
6. Display diff summary (added/dropped/modified columns per table)
7. Generate alter migrations for modifiedTables + addedTables + droppedTables
8. Compute model patches for all modifiedTables
9. If not --force: show model patch summary, prompt to apply
10. Write migrations + patch models
11. SnapshotStore.write() with new payload (update snapshot)
```

## Error Handling

| Scenario | Behavior |
|----------|----------|
| No snapshot found | Error: "Run `generate:dbml` first to create a snapshot." |
| Snapshot version mismatch | Error: "Incompatible snapshot version. Delete `.dbml-sync.json` and re-run `generate:dbml`." |
| DBML parse failure | Error: same as `generate:dbml` — pass through NodeDbmlParser exception |
| Empty diff (no changes) | Info: "No schema changes detected." Exit SUCCESS |
| Dropped table detected | Warning + prompt before generating DROP TABLE migration |
| Forbidden model name in new table | Skip that table (same behavior as `generate:dbml`) |
| Model file has unparseable markers | Warning + prompt: "Adding new markers to User model." |
| `--force` flag | Skip all prompts, write immediately |
| `doctrine/dbal` missing | Warning: "`->change()` requires doctrine/dbal. Run `composer require doctrine/dbal`." (only if modified columns exist) |

## Compatibility

- Same constraints: PHP 8.0+, Laravel 8+
- `->change()` available since Laravel 5.0
- `doctrine/dbal` requirement varies by Laravel version — warn, don't fail
- Many-to-many refs: same silent drop as current code
- New value objects (SchemaDiff, TableDiff, ColumnPair) must NOT use `readonly` class syntax — PHP 8.1+ only. Use plain classes with `public readonly` properties (PHP 8.0+ compatible) or public properties without `readonly` keyword.

## Testing Plan

### Unit tests

- SchemaDiffer: added/dropped tables, added/dropped columns, modified columns, indexes, FKs, empty diff
- SchemaDiff/TableDiff: value object construction
- JsonSnapshotStore: read/write/exists, missing file, version mismatch
- ModelPatcher: replace within markers, insert markers into unmarked model, fresh model with markers
- AlterMigrationGenerator: each change type produces correct up/down methods

### Feature tests

- Full sync flow: generate:dbml → modify DBML → dbml:sync → verify alter migration + model patch
- Sync with no changes: exits cleanly with info message
- Sync without snapshot: exits with error
- --force flag: skips prompts
- Forbidden model names: skipped during sync
- New tables in sync: get Schema::create migrations

### Test fixtures

- `tests/Fixtures/sync-base.dbml` — base schema
- `tests/Fixtures/sync-modified.dbml` — base + added column + dropped column + modified type
- `tests/Fixtures/sync-new-table.dbml` — base + entirely new table

## Files to Create/Modify

### New files

- `src/Commands/SyncDbml.php`
- `src/Parsing/Dbml/SchemaDiffer.php`
- `src/Parsing/Dbml/SchemaDiff.php`
- `src/Parsing/Dbml/TableDiff.php`
- `src/Parsing/Dbml/ColumnPair.php`
- `src/Parsing/Dbml/SnapshotStore.php` (interface)
- `src/Parsing/Dbml/JsonSnapshotStore.php`
- `src/Generation/ColumnDefinitionBuilder.php` — extracted from GenerateFromDbml
- `src/Generation/ModelContentBuilder.php` — extracted from GenerateFromDbml
- `src/Generation/ModelPatcher.php`
- `src/Generation/AlterMigrationGenerator.php`
- `stubs/alter-migration.stub`
- `tests/Unit/SchemaDifferTest.php`
- `tests/Unit/JsonSnapshotStoreTest.php`
- `tests/Unit/ModelPatcherTest.php`
- `tests/Unit/AlterMigrationGeneratorTest.php`
- `tests/Unit/ColumnDefinitionBuilderTest.php` — tests for extracted logic
- `tests/Unit/ModelContentBuilderTest.php` — tests for extracted logic
- `tests/Feature/SyncDbmlCommandTest.php`
- `tests/Fixtures/sync-base.dbml`
- `tests/Fixtures/sync-modified.dbml`
- `tests/Fixtures/sync-new-table.dbml`

### Modified files

- `src/DbmlToLaravelServiceProvider.php` — register SyncDbml command
- `src/Commands/GenerateFromDbml.php` — refactor: delegate to ColumnDefinitionBuilder + ModelContentBuilder, add snapshot write after generation
- `stubs/model.stub` — add @dbml-sync markers to default output
- `README.md` — document dbml:sync command
- `tests/Feature/GenerateFromDbmlCommandTest.php` — verify existing tests still pass after extraction