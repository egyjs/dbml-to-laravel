# Schema Sync / Incremental Migrations Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Add `dbml:sync` Artisan command that generates incremental ALTER migrations and patches Eloquent models by diffing a snapshot against an updated DBML file.

**Architecture:** Extract shared generation logic from `GenerateFromDbml` into `ColumnDefinitionBuilder` and `ModelContentBuilder`. New `SchemaDiffer` produces `SchemaDiff` from two `Schema` objects. `SyncDbml` command consumes diff, generates alter migrations via `AlterMigrationGenerator`, patches models via `ModelPatcher`. `JsonSnapshotStore` persists snapshot alongside DBML file.

**Tech Stack:** PHP 8.0+, Laravel 8+, Pest test framework, Orchestra Testbench

---

## File Structure

### New files

| File | Responsibility |
|------|---------------|
| `src/Generation/ColumnDefinitionBuilder.php` | Build column definitions for migrations (extracted from GenerateFromDbml) |
| `src/Generation/ModelContentBuilder.php` | Build model fillable/casts/relations (extracted from GenerateFromDbml) |
| `src/Parsing/Dbml/SchemaDiff.php` | Value object: added/dropped/modified tables |
| `src/Parsing/Dbml/TableDiff.php` | Per-table diff: added/dropped/modified columns, indexes, FKs |
| `src/Parsing/Dbml/ColumnPair.php` | Old + new Column pair for modification tracking |
| `src/Parsing/Dbml/SchemaDiffer.php` | Compare two Schema objects, produce SchemaDiff |
| `src/Parsing/Dbml/SnapshotStore.php` | Interface: read/write snapshot |
| `src/Parsing/Dbml/JsonSnapshotStore.php` | File-based snapshot (.dbml-sync.json) |
| `src/Generation/AlterMigrationGenerator.php` | Generate Schema::table migrations from TableDiff |
| `src/Generation/ModelPatcher.php` | Patch model files within @dbml-sync markers |
| `src/Commands/SyncDbml.php` | Artisan command `dbml:sync {file} {--force}` |
| `stubs/alter-migration.stub` | Stub for Schema::table migrations |
| `tests/Unit/ColumnDefinitionBuilderTest.php` | Tests for extracted column logic |
| `tests/Unit/ModelContentBuilderTest.php` | Tests for extracted model logic |
| `tests/Unit/SchemaDifferTest.php` | Tests for schema diff engine |
| `tests/Unit/JsonSnapshotStoreTest.php` | Tests for snapshot store |
| `tests/Unit/AlterMigrationGeneratorTest.php` | Tests for alter migration generation |
| `tests/Unit/ModelPatcherTest.php` | Tests for model patching |
| `tests/Feature/SyncDbmlCommandTest.php` | Integration tests for sync command |
| `tests/Fixtures/sync-base.dbml` | Base DBML fixture for sync tests |
| `tests/Fixtures/sync-modified.dbml` | Modified DBML fixture (added/dropped/changed columns) |
| `tests/Fixtures/sync-new-table.dbml` | DBML fixture with a new table added |

### Modified files

| File | Change |
|------|--------|
| `src/Commands/GenerateFromDbml.php` | Refactor: delegate to builders, add snapshot write |
| `src/DbmlToLaravelServiceProvider.php` | Register SyncDbml command |
| `stubs/model.stub` | Add @dbml-sync markers |
| `tests/Feature/GenerateFromDbmlCommandTest.php` | Verify existing tests pass after extraction |

---

## Task 1: Extract ColumnDefinitionBuilder

**Files:**
- Create: `src/Generation/ColumnDefinitionBuilder.php`
- Create: `tests/Unit/ColumnDefinitionBuilderTest.php`
- Modify: `src/Commands/GenerateFromDbml.php`

- [ ] **Step 1: Write failing test for ColumnDefinitionBuilder**

```php
<?php

declare(strict_types=1);

use Egyjs\DbmlToLaravel\Generation\ColumnDefinitionBuilder;
use Egyjs\DbmlToLaravel\Parsing\Dbml\Column;
use Egyjs\DbmlToLaravel\Parsing\Dbml\ColumnDefaultValue;
use Egyjs\DbmlToLaravel\Parsing\Dbml\ColumnType;
use Egyjs\DbmlToLaravel\Parsing\Dbml\EnumDefinition;
use Egyjs\DbmlToLaravel\Parsing\Dbml\EnumValue;

it('builds a string column definition', function () {
    $builder = new ColumnDefinitionBuilder([]);
    $column = new Column('name', new ColumnType('varchar'), false, false, false, false);

    $result = $builder->buildColumnDefinition($column);

    expect($result)->toContain("\$table->string('name', 255)");
    expect($result)->toEndWith(';');
});

it('builds an auto-incrementing primary key', function () {
    $builder = new ColumnDefinitionBuilder([]);
    $column = new Column('id', new ColumnType('int'), true, false, false, true);

    $result = $builder->buildColumnDefinition($column);

    expect($result)->toContain("\$table->increments('id')");
});

it('builds a nullable column', function () {
    $builder = new ColumnDefinitionBuilder([]);
    $column = new Column('email', new ColumnType('varchar'), false, false, false, false);

    $result = $builder->buildColumnDefinition($column);

    expect($result)->toContain('->nullable()');
});

it('builds an enum column using registered enums', function () {
    $enum = new EnumDefinition('user_role', [
        new EnumValue('admin'),
        new EnumValue('editor'),
    ]);
    $builder = new ColumnDefinitionBuilder(['user_role' => $enum]);
    $column = new Column('role', new ColumnType('user_role'), false, false, false, false);

    $result = $builder->buildColumnDefinition($column);

    expect($result)->toContain("\$table->enum('role', ['admin', 'editor'])");
});

it('builds a foreignId column for bigint unsigned FK', function () {
    $refTable = new \Egyjs\DbmlToLaravel\Parsing\Dbml\ReferenceTable('users');
    $ref = new \Egyjs\DbmlToLaravel\Parsing\Dbml\ColumnReference($refTable, 'id');
    $builder = new ColumnDefinitionBuilder([]);
    $column = new Column('user_id', new ColumnType('bigint unsigned'), false, false, false, false, null, [$ref]);

    $result = $builder->buildColumnDefinition($column);

    expect($result)->toContain("\$table->foreignId('user_id')");
    expect($result)->toContain("->constrained('users')");
});
```

- [ ] **Step 2: Run test to verify it fails**

Run: `vendor/bin/pest tests/Unit/ColumnDefinitionBuilderTest.php`
Expected: FAIL — class `ColumnDefinitionBuilder` not found

- [ ] **Step 3: Implement ColumnDefinitionBuilder**

```php
<?php

declare(strict_types=1);

namespace Egyjs\DbmlToLaravel\Generation;

use Egyjs\DbmlToLaravel\Parsing\Dbml\Column;
use Egyjs\DbmlToLaravel\Parsing\Dbml\ColumnDefaultValue;
use Egyjs\DbmlToLaravel\Parsing\Dbml\ColumnReference;
use Egyjs\DbmlToLaravel\Parsing\Dbml\EnumDefinition;
use Egyjs\DbmlToLaravel\Parsing\Dbml\IndexDefinition;

class ColumnDefinitionBuilder
{
    /**
     * @param array<string, EnumDefinition> $enums
     */
    public function __construct(
        private array $enums = []
    ) {}

    /**
     * @param array<string, EnumDefinition> $enums
     */
    public function withEnums(array $enums): self
    {
        return new self($enums);
    }

    public function buildColumnDefinition(Column $column): string
    {
        $name = $column->getName();
        $type = strtolower($column->getType()->getName());
        $reference = $column->getRefs()[0] ?? null;

        $useForeignId = $reference !== null && ($type === 'bigint unsigned' || $type === '');

        $field = $useForeignId
            ? "\$table->foreignId('{$name}')"
            : $this->resolveColumnBaseDefinition($column);

        if ($column->isNull()) {
            $field .= '->nullable()';
        }

        if ($column->getDefaultValue() !== null) {
            $field .= '->default('.$this->formatDefaultValue($column->getDefaultValue()).')';
        }

        if ($column->isUnique() && ! $column->isPrimaryKey()) {
            $field .= '->unique()';
        }

        if ($column->isPrimaryKey() && ! $this->isAutoIncrementingPrimaryKey($column)) {
            $field .= '->primary()';
        }

        if ($reference !== null) {
            $referencedTable = $reference->getRightTable()->getTable();
            $referencedColumn = $reference->getReferencedColumn() ?? 'id';
            $actions = $this->formatForeignKeyActions($reference);

            if ($useForeignId) {
                $constraint = $referencedColumn !== 'id'
                    ? "->constrained('{$referencedTable}', '{$referencedColumn}')"
                    : "->constrained('{$referencedTable}')";

                $field .= $constraint.$actions;

                return "            {$field};";
            }

            $foreignStmt = "\$table->foreign('{$name}')"
                ."->references('{$referencedColumn}')"
                ."->on('{$referencedTable}')"
                .$actions;

            return "            {$field};\n            {$foreignStmt};";
        }

        return "            {$field};";
    }

    public function buildColumnDefinitionWithoutIndent(Column $column): string
    {
        $def = $this->buildColumnDefinition($column);
        return preg_replace('/^            /m', '', $def);
    }

    public function buildIndexDefinition(IndexDefinition $index): ?string
    {
        if (empty($index->getColumns()) || in_array($index->getType(), ['pk', 'primary'], true)) {
            return null;
        }

        $columns = '['.implode(', ', array_map(fn (string $column) => "'{$column}'", $index->getColumns())).']';
        $method = $index->isUnique() ? 'unique' : 'index';
        $name = $index->getName() ? ", '{$index->getName()}'" : '';

        return "            \$table->{$method}({$columns}{$name});";
    }

    public function resolveColumnBaseDefinition(Column $column): string
    {
        $name = $column->getName();
        $type = strtolower($column->getType()->getName());
        $args = $column->getType()->getArgs();

        if ($this->isAutoIncrementingPrimaryKey($column)) {
            return match (true) {
                str_contains($type, 'big') => "\$table->bigIncrements('$name')",
                str_contains($type, 'small') => "\$table->smallIncrements('$name')",
                default => "\$table->increments('$name')",
            };
        }

        if (isset($this->enums[$column->getType()->getName()])) {
            $enumValues = collect($this->enums[$column->getType()->getName()]->getValues())
                ->map(fn ($value) => "'{$value->getValue()}'")
                ->implode(', ');

            return "\$table->enum('$name', [$enumValues])";
        }

        $stringLength = max(1, (int) ($args[0] ?? 255));
        $charLength = max(1, (int) ($args[0] ?? 255));
        $precision = max(1, (int) ($args[0] ?? 8));
        $scale = max(0, (int) ($args[1] ?? 2));

        return match ($type) {
            'varchar', 'string' => "\$table->string('$name', {$stringLength})",
            'char' => "\$table->char('$name', {$charLength})",
            'uuid' => "\$table->uuid('$name')",
            'text', 'longtext' => "\$table->text('$name')",
            'json', 'jsonb' => "\$table->json('$name')",
            'timestamptz', 'timestampz', 'timestamp with time zone' => "\$table->timestampTz('$name')",
            'timestamp', 'datetime' => "\$table->timestamp('$name')",
            'date' => "\$table->date('$name')",
            'time' => "\$table->time('$name')",
            'boolean', 'bool' => "\$table->boolean('$name')",
            'double' => "\$table->double('$name')",
            'float' => "\$table->float('$name')",
            'numeric', 'decimal' => "\$table->decimal('$name', {$precision}, {$scale})",
            'bigint unsigned' => "\$table->unsignedBigInteger('$name')",
            'int unsigned', 'integer unsigned' => "\$table->unsignedInteger('$name')",
            'smallint unsigned' => "\$table->unsignedSmallInteger('$name')",
            'tinyint unsigned' => "\$table->unsignedTinyInteger('$name')",
            'mediumint unsigned' => "\$table->unsignedMediumInteger('$name')",
            'bigint', 'bigserial' => "\$table->bigInteger('$name')",
            'smallint', 'smallserial' => "\$table->smallInteger('$name')",
            'tinyint' => "\$table->tinyInteger('$name')",
            'mediumint' => "\$table->mediumInteger('$name')",
            'serial', 'int', 'integer' => "\$table->integer('$name')",
            default => "\$table->string('$name')",
        };
    }

    public function isAutoIncrementingPrimaryKey(Column $column): bool
    {
        return $column->isPrimaryKey() && $column->isAutoIncrement();
    }

    public function formatDefaultValue(?ColumnDefaultValue $default): string
    {
        if ($default === null) {
            return 'null';
        }

        $value = $default->getValue();

        if ($default->isExpression() && is_string($value)) {
            return "DB::raw('".addslashes($value)."')";
        }

        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }

        if (is_numeric($value)) {
            return (string) $value;
        }

        if ($value === null) {
            return 'null';
        }

        return "'".addslashes((string) $value)."'";
    }

    public function formatForeignKeyActions(ColumnReference $reference): string
    {
        $actions = '';

        if ($reference->getOnDelete()) {
            $actions .= $this->mapForeignAction('delete', $reference->getOnDelete());
        }

        if ($reference->getOnUpdate()) {
            $actions .= $this->mapForeignAction('update', $reference->getOnUpdate());
        }

        return $actions;
    }

    public function mapForeignAction(string $operation, string $action): string
    {
        $operationMethod = ucfirst($operation);

        return match (strtolower($action)) {
            'cascade' => "->cascadeOn{$operationMethod}()",
            'restrict' => "->restrictOn{$operationMethod}()",
            'set null' => "->nullOn{$operationMethod}()",
            default => '',
        };
    }
}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `vendor/bin/pest tests/Unit/ColumnDefinitionBuilderTest.php`
Expected: PASS

- [ ] **Step 5: Refactor GenerateFromDbml to delegate to ColumnDefinitionBuilder**

In `src/Commands/GenerateFromDbml.php`, add a `ColumnDefinitionBuilder` property and delegate all column/index building to it. Remove the duplicated private methods.

Key changes:
- Add `private ColumnDefinitionBuilder $columnBuilder;` property
- In `handle()`, after parsing enums: `$this->columnBuilder = new ColumnDefinitionBuilder($this->enums);`
- Replace `$this->buildColumnDefinition(...)` calls with `$this->columnBuilder->buildColumnDefinition(...)`
- Replace `$this->resolveColumnBaseDefinition(...)` calls with `$this->columnBuilder->resolveColumnBaseDefinition(...)`
- Replace `$this->buildIndexDefinition(...)` calls with `$this->columnBuilder->buildIndexDefinition(...)`
- Remove these private methods from `GenerateFromDbml`: `buildColumnDefinition`, `resolveColumnBaseDefinition`, `formatDefaultValue`, `formatForeignKeyActions`, `mapForeignAction`, `isAutoIncrementingPrimaryKey`, `buildIndexDefinition`

- [ ] **Step 6: Run existing tests to verify no regression**

Run: `vendor/bin/pest tests/Feature/GenerateFromDbmlCommandTest.php`
Expected: All PASS

- [ ] **Step 7: Commit**

```bash
git add src/Generation/ColumnDefinitionBuilder.php tests/Unit/ColumnDefinitionBuilderTest.php src/Commands/GenerateFromDbml.php
git commit -m "refactor: extract ColumnDefinitionBuilder from GenerateFromDbml"
```

---

## Task 2: Extract ModelContentBuilder

**Files:**
- Create: `src/Generation/ModelContentBuilder.php`
- Create: `tests/Unit/ModelContentBuilderTest.php`
- Modify: `src/Commands/GenerateFromDbml.php`

- [ ] **Step 1: Write failing test for ModelContentBuilder**

```php
<?php

declare(strict_types=1);

use Egyjs\DbmlToLaravel\Generation\ModelContentBuilder;
use Egyjs\DbmlToLaravel\Parsing\Dbml\Column;
use Egyjs\DbmlToLaravel\Parsing\Dbml\ColumnType;
use Egyjs\DbmlToLaravel\Parsing\Dbml\Schema;
use Egyjs\DbmlToLaravel\Parsing\Dbml\Table;

it('generates fillable from columns', function () {
    $builder = new ModelContentBuilder;
    $columns = [
        new Column('id', new ColumnType('int'), true, false, false, true),
        new Column('name', new ColumnType('varchar'), false, false, false, false),
        new Column('email', new ColumnType('varchar'), false, true, false, false),
    ];

    $fillable = $builder->generateFillable($columns);

    expect($fillable)->toBe(["'name'", "'email'"]);
});

it('excludes created_at, updated_at, and id from fillable', function () {
    $builder = new ModelContentBuilder;
    $columns = [
        new Column('id', new ColumnType('int'), true, false, false, true),
        new Column('created_at', new ColumnType('datetime'), false, false, false, false),
        new Column('updated_at', new ColumnType('datetime'), false, false, false, false),
        new Column('title', new ColumnType('varchar'), false, false, false, false),
    ];

    $fillable = $builder->generateFillable($columns);

    expect($fillable)->toBe(["'title'"]);
});

it('generates casts filtering out defaults', function () {
    $builder = new ModelContentBuilder;
    $columns = [
        new Column('is_active', new ColumnType('boolean'), false, false, false, false),
        new Column('name', new ColumnType('varchar'), false, false, false, false),
        new Column('settings', new ColumnType('json'), false, false, false, false),
    ];

    $casts = $builder->generateCasts($columns);

    expect($casts)->toBe(['is_active' => 'boolean', 'settings' => 'array']);
});

it('generates table property when name diverges from convention', function () {
    $builder = new ModelContentBuilder;

    expect($builder->generateTableProperty('users', 'User'))->toBe('');
    expect($builder->generateTableProperty('custom_users', 'User'))->toBe("protected \$table = 'custom_users';");
});

it('generates belongsTo relations from columns with refs', function () {
    $builder = new ModelContentBuilder;
    $refTable = new \Egyjs\DbmlToLaravel\Parsing\Dbml\ReferenceTable('users');
    $ref = new \Egyjs\DbmlToLaravel\Parsing\Dbml\ColumnReference($refTable, 'id');
    $columns = [
        new Column('user_id', new ColumnType('int'), false, false, false, false, null, [$ref]),
    ];

    $relations = $builder->generateBelongsToRelations($columns);

    expect($relations)->toHaveCount(1);
    expect($relations[0])->toBe([
        'type' => 'belongsTo',
        'method' => 'user',
        'relatedTable' => 'User',
        'foreignKey' => 'user_id',
        'ownerKey' => 'id',
    ]);
});
```

- [ ] **Step 2: Run test to verify it fails**

Run: `vendor/bin/pest tests/Unit/ModelContentBuilderTest.php`
Expected: FAIL — class `ModelContentBuilder` not found

- [ ] **Step 3: Implement ModelContentBuilder**

```php
<?php

declare(strict_types=1);

namespace Egyjs\DbmlToLaravel\Generation;

use Egyjs\DbmlToLaravel\Parsing\Dbml\Column;
use Egyjs\DbmlToLaravel\Parsing\Dbml\Schema;
use Egyjs\DbmlToLaravel\Parsing\Dbml\Table;
use Illuminate\Support\Str;

class ModelContentBuilder
{
    public function generateFillable(array $columns): array
    {
        return collect($columns)
            ->filter(fn (Column $col) => ! $col->isPrimaryKey() &&
                ! in_array($col->getName(), ['created_at', 'updated_at', 'id'], true)
            )
            ->map(fn (Column $col) => "'".$col->getName()."'")
            ->values()
            ->toArray();
    }

    public function generateCasts(array $columns): array
    {
        return collect($columns)
            ->mapWithKeys(fn (Column $col) => [
                $col->getName() => $this->mapCastType($col),
            ])
            ->filter(fn ($value) => ! empty($value) && ! in_array($value, ['string', 'integer'], true))
            ->toArray();
    }

    public function mapCastType(Column $column): string
    {
        $type = strtolower($column->getType()->getName());
        $args = $column->getType()->getArgs();

        return match ($type) {
            'bool', 'boolean' => 'boolean',
            'json', 'jsonb' => 'array',
            'timestamp', 'datetime', 'timestamptz', 'timestampz', 'timestamp with time zone' => 'datetime',
            'date' => 'date',
            'time' => 'datetime',
            'int', 'integer', 'bigint', 'smallint', 'tinyint' => 'integer',
            'decimal', 'numeric' => isset($args[1]) ? 'decimal:'.(int) $args[1] : 'float',
            'double', 'float' => 'float',
            default => '',
        };
    }

    public function generateTableProperty(string $tableName, string $modelName): string
    {
        $expectedTableName = Str::snake(Str::plural($modelName));

        if ($tableName !== $expectedTableName) {
            return "protected \$table = '$tableName';";
        }

        return '';
    }

    public function generateBelongsToRelations(array $columns): array
    {
        return collect($columns)
            ->filter(fn (Column $col) => count($col->getRefs()) > 0)
            ->map(function (Column $col) {
                $reference = $col->getRefs()[0];
                $relatedTable = Str::studly(Str::singular($reference->getRightTable()->getTable()));

                return [
                    'type' => 'belongsTo',
                    'method' => Str::camel($relatedTable),
                    'relatedTable' => $relatedTable,
                    'foreignKey' => $col->getName(),
                    'ownerKey' => $reference->getReferencedColumn(),
                ];
            })
            ->values()
            ->toArray();
    }

    public function generateHasManyRelations(Table $table, Schema $schema): array
    {
        $relations = [];

        foreach ($schema->getTables() as $candidate) {
            foreach ($candidate->getColumns() as $column) {
                foreach ($column->getRefs() as $reference) {
                    if (strcasecmp($reference->getRightTable()->getTable(), $table->getName()) !== 0) {
                        continue;
                    }

                    $relatedTable = Str::studly(Str::singular($candidate->getName()));
                    $method = Str::camel(Str::studly(Str::plural($relatedTable)));
                    $key = $method.':'.$candidate->getName();

                    if (isset($relations[$key])) {
                        continue;
                    }

                    $relations[$key] = [
                        'type' => 'hasMany',
                        'method' => $method,
                        'relatedTable' => $relatedTable,
                        'foreignKey' => $column->getName(),
                        'localKey' => $reference->getReferencedColumn() ?? 'id',
                    ];
                }
            }
        }

        return array_values($relations);
    }

    public function parseRelations(array $relations): string
    {
        if (empty($relations)) {
            return '';
        }

        return collect($relations)
            ->map(function (array $relation) {
                $methodSignature = "    public function {$relation['method']}()";
                $body = $this->formatRelationBody($relation);

                return "$methodSignature\n    {\n        $body\n    }\n";
            })
            ->implode("\n");
    }

    public function formatRelationBody(array $relation): string
    {
        return match ($relation['type']) {
            'hasMany' => "return \$this->hasMany({$relation['relatedTable']}::class, '{$relation['foreignKey']}', '{$relation['localKey']}');",
            default => "return \$this->belongsTo({$relation['relatedTable']}::class, '{$relation['foreignKey']}'".($relation['ownerKey'] ? ", '{$relation['ownerKey']}'" : '').');',
        };
    }

    public function formatModelContent(Table $table, string $modelName, Schema $schema, string $stub): ?string
    {
        $columns = $table->getColumns();
        $fillable = $this->generateFillable($columns);
        $casts = $this->generateCasts($columns);
        $relations = $this->parseRelations(array_merge(
            $this->generateBelongsToRelations($columns),
            $this->generateHasManyRelations($table, $schema)
        ));
        $tableProperty = $this->generateTableProperty($table->getName(), $modelName);

        $tab = str_repeat("\t", 2);
        $castsString = '';

        if (! empty($casts)) {
            $castsString = implode(",\n$tab", array_map(
                fn ($key, $value) => "'$key' => '$value'",
                array_keys($casts),
                $casts
            ));
        }

        $fillableString = '';
        if (! empty($fillable)) {
            $fillableString = implode(",\n$tab", $fillable);
        }

        return str_replace(
            ['{{ modelName }}', '{{ tableProperty }}', '{{ fillable }}', '{{ casts }}', '{{ relations }}'],
            [$modelName, $tableProperty, $fillableString, $castsString, $relations],
            $stub
        );
    }
}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `vendor/bin/pest tests/Unit/ModelContentBuilderTest.php`
Expected: PASS

- [ ] **Step 5: Refactor GenerateFromDbml to delegate to ModelContentBuilder**

Key changes to `src/Commands/GenerateFromDbml.php`:
- Add `private ModelContentBuilder $modelBuilder;` property
- In `handle()`: `$this->modelBuilder = new ModelContentBuilder;`
- Replace `$this->generateModelContent(...)` body with delegation to `$this->modelBuilder->formatModelContent(...)`
- Remove these private methods from `GenerateFromDbml`: `generateFillable`, `generateCasts`, `mapCastType`, `generateTableProperty`, `generateBelongsToRelations`, `generateHasManyRelations`, `parseRelations`, `formatRelationBody`, `generateModelContent`

- [ ] **Step 6: Run existing tests to verify no regression**

Run: `vendor/bin/pest tests/Feature/GenerateFromDbmlCommandTest.php`
Expected: All PASS

- [ ] **Step 7: Commit**

```bash
git add src/Generation/ModelContentBuilder.php tests/Unit/ModelContentBuilderTest.php src/Commands/GenerateFromDbml.php
git commit -m "refactor: extract ModelContentBuilder from GenerateFromDbml"
```

---

## Task 3: Create SchemaDiff, TableDiff, and ColumnPair value objects

**Files:**
- Create: `src/Parsing/Dbml/SchemaDiff.php`
- Create: `src/Parsing/Dbml/TableDiff.php`
- Create: `src/Parsing/Dbml/ColumnPair.php`

- [ ] **Step 1: Implement SchemaDiff**

```php
<?php

declare(strict_types=1);

namespace Egyjs\DbmlToLaravel\Parsing\Dbml;

class SchemaDiff
{
    /**
     * @param  Table[]  $addedTables
     * @param  string[]  $droppedTables
     * @param  TableDiff[]  $modifiedTables
     */
    public function __construct(
        public array $addedTables = [],
        public array $droppedTables = [],
        public array $modifiedTables = []
    ) {}

    public function isEmpty(): bool
    {
        return empty($this->addedTables)
            && empty($this->droppedTables)
            && empty($this->modifiedTables);
    }
}
```

- [ ] **Step 2: Implement TableDiff**

```php
<?php

declare(strict_types=1);

namespace Egyjs\DbmlToLaravel\Parsing\Dbml;

class TableDiff
{
    /**
     * @param  Column[]  $addedColumns
     * @param  Column[]  $droppedColumns
     * @param  ColumnPair[]  $modifiedColumns
     * @param  IndexDefinition[]  $addedIndexes
     * @param  IndexDefinition[]  $droppedIndexes
     * @param  ColumnReference[]  $addedForeignKeys
     * @param  ColumnReference[]  $droppedForeignKeys
     */
    public function __construct(
        public string $tableName = '',
        public array $addedColumns = [],
        public array $droppedColumns = [],
        public array $modifiedColumns = [],
        public array $addedIndexes = [],
        public array $droppedIndexes = [],
        public array $addedForeignKeys = [],
        public array $droppedForeignKeys = []
    ) {}

    public function isEmpty(): bool
    {
        return empty($this->addedColumns)
            && empty($this->droppedColumns)
            && empty($this->modifiedColumns)
            && empty($this->addedIndexes)
            && empty($this->droppedIndexes)
            && empty($this->addedForeignKeys)
            && empty($this->droppedForeignKeys);
    }
}
```

- [ ] **Step 3: Implement ColumnPair**

```php
<?php

declare(strict_types=1);

namespace Egyjs\DbmlToLaravel\Parsing\Dbml;

class ColumnPair
{
    public Column $old;
    public Column $new;

    public function __construct(Column $old, Column $new)
    {
        $this->old = $old;
        $this->new = $new;
    }
}
```

- [ ] **Step 4: Commit**

```bash
git add src/Parsing/Dbml/SchemaDiff.php src/Parsing/Dbml/TableDiff.php src/Parsing/Dbml/ColumnPair.php
git commit -m "feat: add SchemaDiff, TableDiff, and ColumnPair value objects"
```

---

## Task 4: Implement SchemaDiffer

**Files:**
- Create: `src/Parsing/Dbml/SchemaDiffer.php`
- Create: `tests/Unit/SchemaDifferTest.php`

- [ ] **Step 1: Write failing test for SchemaDiffer**

```php
<?php

declare(strict_types=1);

use Egyjs\DbmlToLaravel\Parsing\Dbml\Column;
use Egyjs\DbmlToLaravel\Parsing\Dbml\ColumnType;
use Egyjs\DbmlToLaravel\Parsing\Dbml\Schema;
use Egyjs\DbmlToLaravel\Parsing\Dbml\SchemaDiffer;
use Egyjs\DbmlToLaravel\Parsing\Dbml\Table;

it('detects added tables', function () {
    $old = new Schema([
        new Table('users', 'public', [new Column('id', new ColumnType('int'), true, false, false, true)]),
    ], []);

    $new = new Schema([
        new Table('users', 'public', [new Column('id', new ColumnType('int'), true, false, false, true)]),
        new Table('posts', 'public', [new Column('id', new ColumnType('int'), true, false, false, true)]),
    ], []);

    $diff = SchemaDiffer::diff($old, $new);

    expect($diff->addedTables)->toHaveCount(1);
    expect($diff->addedTables[0]->getName())->toBe('posts');
    expect($diff->droppedTables)->toHaveCount(0);
    expect($diff->modifiedTables)->toHaveCount(0);
});

it('detects dropped tables', function () {
    $old = new Schema([
        new Table('users', 'public', [new Column('id', new ColumnType('int'), true, false, false, true)]),
        new Table('posts', 'public', [new Column('id', new ColumnType('int'), true, false, false, true)]),
    ], []);

    $new = new Schema([
        new Table('users', 'public', [new Column('id', new ColumnType('int'), true, false, false, true)]),
    ], []);

    $diff = SchemaDiffer::diff($old, $new);

    expect($diff->droppedTables)->toHaveCount(1);
    expect($diff->droppedTables[0])->toBe('posts');
    expect($diff->addedTables)->toHaveCount(0);
});

it('detects added columns', function () {
    $old = new Schema([
        new Table('users', 'public', [new Column('id', new ColumnType('int'), true, false, false, true)]),
    ], []);

    $new = new Schema([
        new Table('users', 'public', [
            new Column('id', new ColumnType('int'), true, false, false, true),
            new Column('email', new ColumnType('varchar'), false, false, false, false),
        ]),
    ], []);

    $diff = SchemaDiffer::diff($old, $new);

    expect($diff->modifiedTables)->toHaveCount(1);
    expect($diff->modifiedTables[0]->addedColumns)->toHaveCount(1);
    expect($diff->modifiedTables[0]->addedColumns[0]->getName())->toBe('email');
});

it('detects dropped columns', function () {
    $old = new Schema([
        new Table('users', 'public', [
            new Column('id', new ColumnType('int'), true, false, false, true),
            new Column('email', new ColumnType('varchar'), false, false, false, false),
        ]),
    ], []);

    $new = new Schema([
        new Table('users', 'public', [new Column('id', new ColumnType('int'), true, false, false, true)]),
    ], []);

    $diff = SchemaDiffer::diff($old, $new);

    expect($diff->modifiedTables)->toHaveCount(1);
    expect($diff->modifiedTables[0]->droppedColumns)->toHaveCount(1);
    expect($diff->modifiedTables[0]->droppedColumns[0]->getName())->toBe('email');
});

it('detects modified columns', function () {
    $old = new Schema([
        new Table('users', 'public', [
            new Column('name', new ColumnType('varchar'), false, false, false, false),
        ]),
    ], []);

    $new = new Schema([
        new Table('users', 'public', [
            new Column('name', new ColumnType('varchar'), false, false, true, false),
        ]),
    ], []);

    $diff = SchemaDiffer::diff($old, $new);

    expect($diff->modifiedTables)->toHaveCount(1);
    expect($diff->modifiedTables[0]->modifiedColumns)->toHaveCount(1);
    expect($diff->modifiedTables[0]->modifiedColumns[0]->old->isNotNull())->toBeFalse();
    expect($diff->modifiedTables[0]->modifiedColumns[0]->new->isNotNull())->toBeTrue();
});

it('returns empty diff for identical schemas', function () {
    $schema = new Schema([
        new Table('users', 'public', [new Column('id', new ColumnType('int'), true, false, false, true)]),
    ], []);

    $diff = SchemaDiffer::diff($schema, $schema);

    expect($diff->isEmpty())->toBeTrue();
});
```

- [ ] **Step 2: Run test to verify it fails**

Run: `vendor/bin/pest tests/Unit/SchemaDifferTest.php`
Expected: FAIL — class `SchemaDiffer` not found

- [ ] **Step 3: Implement SchemaDiffer**

```php
<?php

declare(strict_types=1);

namespace Egyjs\DbmlToLaravel\Parsing\Dbml;

class SchemaDiffer
{
    public static function diff(Schema $old, Schema $new): SchemaDiff
    {
        $oldTableMap = self::indexTablesByName($old->getTables());
        $newTableMap = self::indexTablesByName($new->getTables());

        $addedTables = [];
        $droppedTables = [];
        $modifiedTables = [];

        foreach ($newTableMap as $name => $table) {
            if (! isset($oldTableMap[$name])) {
                $addedTables[] = $table;
                continue;
            }

            $tableDiff = self::diffTable($oldTableMap[$name], $table);
            if (! $tableDiff->isEmpty()) {
                $modifiedTables[] = $tableDiff;
            }
        }

        foreach ($oldTableMap as $name => $table) {
            if (! isset($newTableMap[$name])) {
                $droppedTables[] = $name;
            }
        }

        return new SchemaDiff($addedTables, $droppedTables, $modifiedTables);
    }

    /**
     * @param  Table[]  $tables
     * @return array<string, Table>
     */
    private static function indexTablesByName(array $tables): array
    {
        $map = [];
        foreach ($tables as $table) {
            $map[strtolower($table->getName())] = $table;
        }

        return $map;
    }

    private static function diffTable(Table $old, Table $new): TableDiff
    {
        $oldColumnMap = self::indexColumnsByName($old->getColumns());
        $newColumnMap = self::indexColumnsByName($new->getColumns());

        $addedColumns = [];
        $droppedColumns = [];
        $modifiedColumns = [];

        foreach ($newColumnMap as $name => $column) {
            if (! isset($oldColumnMap[$name])) {
                $addedColumns[] = $column;
                continue;
            }

            if (self::columnChanged($oldColumnMap[$name], $column)) {
                $modifiedColumns[] = new ColumnPair($oldColumnMap[$name], $column);
            }
        }

        foreach ($oldColumnMap as $name => $column) {
            if (! isset($newColumnMap[$name])) {
                $droppedColumns[] = $column;
            }
        }

        [$addedIndexes, $droppedIndexes] = self::diffIndexes($old->getIndexes(), $new->getIndexes());

        [$addedFks, $droppedFks] = self::diffForeignKeys($old->getColumns(), $new->getColumns());

        return new TableDiff(
            $new->getName(),
            $addedColumns,
            $droppedColumns,
            $modifiedColumns,
            $addedIndexes,
            $droppedIndexes,
            $addedFks,
            $droppedFks
        );
    }

    /**
     * @param  Column[]  $columns
     * @return array<string, Column>
     */
    private static function indexColumnsByName(array $columns): array
    {
        $map = [];
        foreach ($columns as $column) {
            $map[strtolower($column->getName())] = $column;
        }

        return $map;
    }

    private static function columnChanged(Column $old, Column $new): bool
    {
        return strtolower($old->getType()->getName()) !== strtolower($new->getType()->getName())
            || $old->getType()->getArgs() !== $new->getType()->getArgs()
            || $old->isNull() !== $new->isNull()
            || $old->getDefaultValue() !== $new->getDefaultValue()
            || $old->isUnique() !== $new->isUnique()
            || $old->isPrimaryKey() !== $new->isPrimaryKey()
            || $old->isAutoIncrement() !== $new->isAutoIncrement();
    }

    /**
     * @param  IndexDefinition[]  $oldIndexes
     * @param  IndexDefinition[]  $newIndexes
     * @return array{IndexDefinition[], IndexDefinition[]}
     */
    private static function diffIndexes(array $oldIndexes, array $newIndexes): array
    {
        $oldIndexMap = self::indexIndexesByColumns($oldIndexes);
        $newIndexMap = self::indexIndexesByColumns($newIndexes);

        $added = [];
        $dropped = [];

        foreach ($newIndexMap as $key => $index) {
            if (! isset($oldIndexMap[$key])) {
                $added[] = $index;
            }
        }

        foreach ($oldIndexMap as $key => $index) {
            if (! isset($newIndexMap[$key])) {
                $dropped[] = $index;
            }
        }

        return [$added, $dropped];
    }

    /**
     * @param  IndexDefinition[]  $indexes
     * @return array<string, IndexDefinition>
     */
    private static function indexIndexesByColumns(array $indexes): array
    {
        $map = [];
        foreach ($indexes as $index) {
            if (empty($index->getColumns()) || in_array($index->getType(), ['pk', 'primary'], true)) {
                continue;
            }
            $key = implode(',', array_map('strtolower', $index->getColumns())) . ($index->isUnique() ? ':unique' : '');
            $map[$key] = $index;
        }

        return $map;
    }

    /**
     * @param  Column[]  $oldColumns
     * @param  Column[]  $newColumns
     * @return array{ColumnReference[], ColumnReference[]}
     */
    private static function diffForeignKeys(array $oldColumns, array $newColumns): array
    {
        $oldFkMap = self::collectForeignKeys($oldColumns);
        $newFkMap = self::collectForeignKeys($newColumns);

        $added = [];
        $dropped = [];

        foreach ($newFkMap as $key => $ref) {
            if (! isset($oldFkMap[$key])) {
                $added[] = $ref;
            }
        }

        foreach ($oldFkMap as $key => $ref) {
            if (! isset($newFkMap[$key])) {
                $dropped[] = $ref;
            }
        }

        return [$added, $dropped];
    }

    /**
     * @param  Column[]  $columns
     * @return array<string, ColumnReference>
     */
    private static function collectForeignKeys(array $columns): array
    {
        $map = [];
        foreach ($columns as $column) {
            foreach ($column->getRefs() as $ref) {
                $refTable = $ref->getRightTable()->getTable();
                $refCol = $ref->getReferencedColumn() ?? 'id';
                $key = strtolower($column->getName()) . '->' . strtolower($refTable) . '.' . strtolower($refCol);
                $map[$key] = $ref;
            }
        }

        return $map;
    }
}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `vendor/bin/pest tests/Unit/SchemaDifferTest.php`
Expected: PASS

- [ ] **Step 5: Commit**

```bash
git add src/Parsing/Dbml/SchemaDiffer.php tests/Unit/SchemaDifferTest.php
git commit -m "feat: add SchemaDiffer for comparing two Schema objects"
```

---

## Task 5: Implement SnapshotStore interface and JsonSnapshotStore

**Files:**
- Create: `src/Parsing/Dbml/SnapshotStore.php`
- Create: `src/Parsing/Dbml/JsonSnapshotStore.php`
- Create: `tests/Unit/JsonSnapshotStoreTest.php`

- [ ] **Step 1: Write failing test for JsonSnapshotStore**

```php
<?php

declare(strict_types=1);

use Egyjs\DbmlToLaravel\Parsing\Dbml\JsonSnapshotStore;
use Egyjs\DbmlToLaravel\Parsing\Dbml\Schema;

it('writes and reads a snapshot', function () {
    $dir = sys_get_temp_dir().'/snapshot_test_'.uniqid();
    mkdir($dir, 0755, true);
    $dbmlPath = $dir.'/schema.dbml';
    file_put_contents($dbmlPath, '');

    $store = new JsonSnapshotStore;
    $payload = [
        'version' => 1,
        'generated_at' => '2026-05-16T10:00:00Z',
        'tables' => [],
        'enums' => [],
        'refs' => [],
    ];

    $store->write($dbmlPath, $payload);

    expect($store->exists($dbmlPath))->toBeTrue();

    $schema = $store->read($dbmlPath);
    expect($schema)->toBeInstanceOf(Schema::class);

    unlink($dir.'/.dbml-sync.json');
    unlink($dbmlPath);
    rmdir($dir);
});

it('returns null when no snapshot exists', function () {
    $dir = sys_get_temp_dir().'/snapshot_test_'.uniqid();
    mkdir($dir, 0755, true);
    $dbmlPath = $dir.'/schema.dbml';
    file_put_contents($dbmlPath, '');

    $store = new JsonSnapshotStore;

    expect($store->exists($dbmlPath))->toBeFalse();
    expect($store->read($dbmlPath))->toBeNull();

    unlink($dbmlPath);
    rmdir($dir);
});

it('throws on version mismatch', function () {
    $dir = sys_get_temp_dir().'/snapshot_test_'.uniqid();
    mkdir($dir, 0755, true);
    $dbmlPath = $dir.'/schema.dbml';
    file_put_contents($dbmlPath, '');

    $store = new JsonSnapshotStore;
    $payload = [
        'version' => 99,
        'generated_at' => '2026-05-16T10:00:00Z',
        'tables' => [],
        'enums' => [],
        'refs' => [],
    ];

    $store->write($dbmlPath, $payload);
    $store->read($dbmlPath);
})->throws(RuntimeException::class);

it('stores snapshot adjacent to DBML file', function () {
    $dir = sys_get_temp_dir().'/snapshot_test_'.uniqid();
    mkdir($dir, 0755, true);
    $dbmlPath = $dir.'/schema.dbml';
    file_put_contents($dbmlPath, '');

    $store = new JsonSnapshotStore;
    $store->write($dbmlPath, [
        'version' => 1,
        'generated_at' => '2026-05-16T10:00:00Z',
        'tables' => [],
        'enums' => [],
        'refs' => [],
    ]);

    expect(file_exists($dir.'/.dbml-sync.json'))->toBeTrue();

    unlink($dir.'/.dbml-sync.json');
    unlink($dbmlPath);
    rmdir($dir);
});
```

- [ ] **Step 2: Run test to verify it fails**

Run: `vendor/bin/pest tests/Unit/JsonSnapshotStoreTest.php`
Expected: FAIL — class `JsonSnapshotStore` not found

- [ ] **Step 3: Implement SnapshotStore interface**

```php
<?php

declare(strict_types=1);

namespace Egyjs\DbmlToLaravel\Parsing\Dbml;

interface SnapshotStore
{
    public function read(string $dbmlPath): ?Schema;

    public function write(string $dbmlPath, array $jsonPayload): void;

    public function exists(string $dbmlPath): bool;
}
```

- [ ] **Step 4: Implement JsonSnapshotStore**

```php
<?php

declare(strict_types=1);

namespace Egyjs\DbmlToLaravel\Parsing\Dbml;

use RuntimeException;

class JsonSnapshotStore implements SnapshotStore
{
    private const CURRENT_VERSION = 1;

    public function read(string $dbmlPath): ?Schema
    {
        if (! $this->exists($dbmlPath)) {
            return null;
        }

        $content = file_get_contents($this->snapshotPath($dbmlPath));
        $payload = json_decode($content, true, 512, JSON_THROW_ON_ERROR);

        if (($payload['version'] ?? 0) !== self::CURRENT_VERSION) {
            throw new RuntimeException(
                'Incompatible snapshot version. Delete `.dbml-sync.json` and re-run `generate:dbml`.'
            );
        }

        return SchemaFactory::fromArray($payload);
    }

    public function write(string $dbmlPath, array $jsonPayload): void
    {
        $jsonPayload['version'] = self::CURRENT_VERSION;
        $jsonPayload['generated_at'] = date('c');

        $dir = dirname($dbmlPath);
        if (! is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        file_put_contents(
            $this->snapshotPath($dbmlPath),
            json_encode($jsonPayload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)
        );
    }

    public function exists(string $dbmlPath): bool
    {
        return file_exists($this->snapshotPath($dbmlPath));
    }

    private function snapshotPath(string $dbmlPath): string
    {
        return dirname($dbmlPath).'/.dbml-sync.json';
    }
}
```

- [ ] **Step 5: Run test to verify it passes**

Run: `vendor/bin/pest tests/Unit/JsonSnapshotStoreTest.php`
Expected: PASS

- [ ] **Step 6: Commit**

```bash
git add src/Parsing/Dbml/SnapshotStore.php src/Parsing/Dbml/JsonSnapshotStore.php tests/Unit/JsonSnapshotStoreTest.php
git commit -m "feat: add SnapshotStore interface and JsonSnapshotStore"
```

---

## Task 6: Create alter-migration stub and AlterMigrationGenerator

**Files:**
- Create: `stubs/alter-migration.stub`
- Create: `src/Generation/AlterMigrationGenerator.php`
- Create: `tests/Unit/AlterMigrationGeneratorTest.php`

- [ ] **Step 1: Create alter-migration stub**

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

- [ ] **Step 2: Write failing test for AlterMigrationGenerator**

```php
<?php

declare(strict_types=1);

use Egyjs\DbmlToLaravel\Generation\AlterMigrationGenerator;
use Egyjs\DbmlToLaravel\Generation\ColumnDefinitionBuilder;
use Egyjs\DbmlToLaravel\Parsing\Dbml\Column;
use Egyjs\DbmlToLaravel\Parsing\Dbml\ColumnPair;
use Egyjs\DbmlToLaravel\Parsing\Dbml\ColumnType;
use Egyjs\DbmlToLaravel\Parsing\Dbml\TableDiff;

it('generates add column in up and dropColumn in down', function () {
    $generator = new AlterMigrationGenerator(new ColumnDefinitionBuilder([]));
    $diff = new TableDiff(
        tableName: 'users',
        addedColumns: [new Column('phone', new ColumnType('varchar'), false, false, false, false)]
    );

    $content = $generator->generate($diff, 'stubs/alter-migration.stub');

    expect($content)->toContain("Schema::table('users'");
    expect($content)->toContain("\$table->string('phone', 255)");
    expect($content)->toContain("\$table->dropColumn('phone')");
});

it('generates dropColumn in up and recreate in down', function () {
    $generator = new AlterMigrationGenerator(new ColumnDefinitionBuilder([]));
    $diff = new TableDiff(
        tableName: 'users',
        droppedColumns: [new Column('phone', new ColumnType('varchar'), false, false, false, false)]
    );

    $content = $generator->generate($diff, 'stubs/alter-migration.stub');

    expect($content)->toContain("\$table->dropColumn('phone')");
    expect($content)->toContain("\$table->string('phone', 255)");
});

it('generates change column with ->change()', function () {
    $generator = new AlterMigrationGenerator(new ColumnDefinitionBuilder([]));
    $old = new Column('name', new ColumnType('varchar'), false, false, false, false);
    $new = new Column('name', new ColumnType('varchar'), false, false, true, false);
    $diff = new TableDiff(
        tableName: 'users',
        modifiedColumns: [new ColumnPair($old, $new)]
    );

    $content = $generator->generate($diff, 'stubs/alter-migration.stub');

    expect($content)->toContain("->change()");
});
```

- [ ] **Step 3: Run test to verify it fails**

Run: `vendor/bin/pest tests/Unit/AlterMigrationGeneratorTest.php`
Expected: FAIL — class `AlterMigrationGenerator` not found

- [ ] **Step 4: Implement AlterMigrationGenerator**

```php
<?php

declare(strict_types=1);

namespace Egyjs\DbmlToLaravel\Generation;

use Egyjs\DbmlToLaravel\Parsing\Dbml\ColumnPair;
use Egyjs\DbmlToLaravel\Parsing\Dbml\TableDiff;

class AlterMigrationGenerator
{
    public function __construct(
        private ColumnDefinitionBuilder $columnBuilder
    ) {}

    public function generate(TableDiff $diff, string $stubContent): string
    {
        $upLines = [];
        $downLines = [];

        foreach ($diff->addedColumns as $column) {
            $upLines[] = '            '.$this->columnBuilder->buildColumnDefinitionWithoutIndent($column);
            $downLines[] = "            \$table->dropColumn('{$column->getName()}');";
        }

        foreach ($diff->droppedColumns as $column) {
            $upLines[] = "            \$table->dropColumn('{$column->getName()}');";
            $downLines[] = '            '.$this->columnBuilder->buildColumnDefinitionWithoutIndent($column);
        }

        foreach ($diff->modifiedColumns as $pair) {
            $upLines[] = '            '.$this->buildChangeColumn($pair->new).';';
            $downLines[] = '            '.$this->buildChangeColumn($pair->old).';';
        }

        foreach ($diff->addedIndexes as $index) {
            $upLines[] = $this->columnBuilder->buildIndexDefinition($index);
            $indexDef = $this->columnBuilder->buildIndexDefinition($index);
            if ($indexDef !== null) {
                $downLines[] = $this->buildDropIndex($index);
            }
        }

        foreach ($diff->droppedIndexes as $index) {
            $upLines[] = $this->buildDropIndex($index);
            $indexDef = $this->columnBuilder->buildIndexDefinition($index);
            if ($indexDef !== null) {
                $downLines[] = $indexDef;
            }
        }

        foreach ($diff->addedForeignKeys as $fk) {
            $upLines[] = $this->buildAddForeignKey($fk);
            $downLines[] = $this->buildDropForeignKey($fk);
        }

        foreach ($diff->droppedForeignKeys as $fk) {
            $upLines[] = $this->buildDropForeignKey($fk);
            $downLines[] = $this->buildAddForeignKey($fk);
        }

        $upContent = implode("\n", $upLines);
        $downContent = implode("\n", $downLines);

        return str_replace(
            ['{{ tableName }}', '{{ upColumns }}', '{{ downColumns }}'],
            [$diff->tableName, $upContent, $downContent],
            $stubContent
        );
    }

    private function buildChangeColumn(Column $column): string
    {
        $def = $this->columnBuilder->resolveColumnBaseDefinition($column);

        if ($column->isNull()) {
            $def .= '->nullable()';
        }

        if ($column->getDefaultValue() !== null) {
            $def .= '->default('.$this->columnBuilder->formatDefaultValue($column->getDefaultValue()).')';
        }

        if ($column->isUnique() && ! $column->isPrimaryKey()) {
            $def .= '->unique()';
        }

        $def .= '->change()';

        return $def;
    }

    private function buildDropIndex(IndexDefinition $index): string
    {
        $columns = array_map(fn (string $col) => "'{$col}'", $index->getColumns());
        $method = $index->isUnique() ? 'dropUniqueIndex' : 'dropIndex';
        $args = '['.implode(', ', $columns).']';

        if ($index->getName()) {
            $args = "'{$index->getName()}'";
        }

        return "            \$table->{$method}({$args});";
    }

    private function buildAddForeignKey(ColumnReference $ref): string
    {
        $referencedTable = $ref->getRightTable()->getTable();
        $referencedColumn = $ref->getReferencedColumn() ?? 'id';
        $actions = $this->columnBuilder->formatForeignKeyActions($ref);

        return "            \$table->foreign('{$this->fkColumnName}')"
            ."->references('{$referencedColumn}')"
            ."->on('{$referencedTable}')"
            .$actions.';';
    }

    private function buildDropForeignKey(ColumnReference $ref): string
    {
        return "            \$table->dropForeign(['{$this->fkColumnName}']);";
    }
}
```

**Note:** The FK methods above need the column name from context. The `ColumnReference` doesn't carry the source column name. Implementation must pass column name alongside each FK reference. Adjust `TableDiff` to store FK diffs as `['column' => string, 'reference' => ColumnReference]` associative arrays, or add a `ForeignKeyDiff` value object.

**Revised approach for FK diffs:** In `TableDiff`, change `addedForeignKeys` and `droppedForeignKeys` from `ColumnReference[]` to an array of `['column' => string, 'reference' => ColumnReference]`. Update `SchemaDiffer::diffForeignKeys()` to collect column name alongside each reference. Update `AlterMigrationGenerator` to read column name from the array.

- [ ] **Step 5: Update TableDiff FK fields and SchemaDiffer FK collection**

In `TableDiff`, change the docblock and type for `addedForeignKeys` / `droppedForeignKeys`:

```php
/** @var array{column: string, reference: ColumnReference}[] */
public array $addedForeignKeys = [];
/** @var array{column: string, reference: ColumnReference}[] */
public array $droppedForeignKeys = [];
```

In `SchemaDiffer::collectForeignKeys()`, return column name in each entry:

```php
private static function collectForeignKeys(array $columns): array
{
    $map = [];
    foreach ($columns as $column) {
        foreach ($column->getRefs() as $ref) {
            $refTable = $ref->getRightTable()->getTable();
            $refCol = $ref->getReferencedColumn() ?? 'id';
            $key = strtolower($column->getName()) . '->' . strtolower($refTable) . '.' . strtolower($refCol);
            $map[$key] = ['column' => $column->getName(), 'reference' => $ref];
        }
    }
    return $map;
}
```

Update `AlterMigrationGenerator::buildAddForeignKey` and `buildDropForeignKey` to accept the array:

```php
private function buildAddForeignKey(array $fkData): string
{
    $columnName = $fkData['column'];
    $ref = $fkData['reference'];
    $referencedTable = $ref->getRightTable()->getTable();
    $referencedColumn = $ref->getReferencedColumn() ?? 'id';
    $actions = $this->columnBuilder->formatForeignKeyActions($ref);

    return "            \$table->foreign('{$columnName}')"
        ."->references('{$referencedColumn}')"
        ."->on('{$referencedTable}')"
        .$actions.';';
}

private function buildDropForeignKey(array $fkData): string
{
    $columnName = $fkData['column'];

    return "            \$table->dropForeign(['{$columnName}']);";
}
```

- [ ] **Step 6: Run test to verify it passes**

Run: `vendor/bin/pest tests/Unit/AlterMigrationGeneratorTest.php`
Expected: PASS

- [ ] **Step 7: Commit**

```bash
git add stubs/alter-migration.stub src/Generation/AlterMigrationGenerator.php tests/Unit/AlterMigrationGeneratorTest.php src/Parsing/Dbml/TableDiff.php src/Parsing/Dbml/SchemaDiffer.php
git commit -m "feat: add AlterMigrationGenerator and alter-migration stub"
```

---

## Task 7: Implement ModelPatcher

**Files:**
- Create: `src/Generation/ModelPatcher.php`
- Create: `tests/Unit/ModelPatcherTest.php`

- [ ] **Step 1: Write failing test for ModelPatcher**

```php
<?php

declare(strict_types=1);

use Egyjs\DbmlToLaravel\Generation\ModelContentBuilder;
use Egyjs\DbmlToLaravel\Generation\ModelPatcher;
use Egyjs\DbmlToLaravel\Parsing\Dbml\Column;
use Egyjs\DbmlToLaravel\Parsing\Dbml\ColumnType;
use Egyjs\DbmlToLaravel\Parsing\Dbml\Schema;
use Egyjs\DbmlToLaravel\Parsing\Dbml\Table;

it('replaces content within existing markers', function () {
    $modelContent = <<<'PHP'
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class User extends Model
{
    // @dbml-sync:fillable
    protected $fillable = [
        'name',
    ];
    // @enddbml-sync:fillable

    // @dbml-sync:casts
    protected $casts = [];
    // @enddbml-sync:casts
}
PHP;

    $patcher = new ModelPatcher(new ModelContentBuilder);
    $table = new Table('users', 'public', [
        new Column('id', new ColumnType('int'), true, false, false, true),
        new Column('name', new ColumnType('varchar'), false, false, false, false),
        new Column('email', new ColumnType('varchar'), false, true, false, false),
    ]);
    $schema = new Schema([$table], []);

    $result = $patcher->patch($modelContent, $table, 'User', $schema);

    expect($result)->toContain("'name'");
    expect($result)->toContain("'email'");
    expect($result)->toContain('// @dbml-sync:fillable');
    expect($result)->toContain('// @enddbml-sync:fillable');
});

it('inserts markers into unmarked model', function () {
    $modelContent = <<<'PHP'
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class User extends Model
{
    protected $fillable = [
        'name',
    ];

    public function customMethod()
    {
        return true;
    }
}
PHP;

    $patcher = new ModelPatcher(new ModelContentBuilder);
    $table = new Table('users', 'public', [
        new Column('id', new ColumnType('int'), true, false, false, true),
        new Column('name', new ColumnType('varchar'), false, false, false, false),
    ]);
    $schema = new Schema([$table], []);

    $result = $patcher->patch($modelContent, $table, 'User', $schema);

    expect($result)->toContain('// @dbml-sync:fillable');
    expect($result)->toContain('customMethod');
});

it('preserves content outside markers', function () {
    $modelContent = <<<'PHP'
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class User extends Model
{
    use SomeCustomTrait;

    // @dbml-sync:fillable
    protected $fillable = [
        'name',
    ];
    // @enddbml-sync:fillable

    public function isAdmin(): bool
    {
        return true;
    }
}
PHP;

    $patcher = new ModelPatcher(new ModelContentBuilder);
    $table = new Table('users', 'public', [
        new Column('id', new ColumnType('int'), true, false, false, true),
        new Column('name', new ColumnType('varchar'), false, false, false, false),
    ]);
    $schema = new Schema([$table], []);

    $result = $patcher->patch($modelContent, $table, 'User', $schema);

    expect($result)->toContain('use SomeCustomTrait');
    expect($result)->toContain('isAdmin');
});
```

- [ ] **Step 2: Run test to verify it fails**

Run: `vendor/bin/pest tests/Unit/ModelPatcherTest.php`
Expected: FAIL — class `ModelPatcher` not found

- [ ] **Step 3: Implement ModelPatcher**

```php
<?php

declare(strict_types=1);

namespace Egyjs\DbmlToLaravel\Generation;

use Egyjs\DbmlToLaravel\Parsing\Dbml\Schema;
use Egyjs\DbmlToLaravel\Parsing\Dbml\Table;

class ModelPatcher
{
    private const SECTIONS = ['table-property', 'fillable', 'casts', 'relations'];

    public function __construct(
        private ModelContentBuilder $modelBuilder
    ) {}

    public function patch(string $modelContent, Table $table, string $modelName, Schema $schema): string
    {
        $sections = $this->generateSections($table, $modelName, $schema);

        foreach (self::SECTIONS as $section) {
            $marker = "@dbml-sync:{$section}";
            $endMarker = "@enddbml-sync:{$section}";

            if ($sections[$section] === null) {
                continue;
            }

            $content = $sections[$section];

            if ($this->hasMarkerPair($modelContent, $marker, $endMarker)) {
                $modelContent = $this->replaceBetweenMarkers($modelContent, $marker, $endMarker, $content);
            } else {
                $modelContent = $this->insertMarkerBlock($modelContent, $section, $content);
            }
        }

        return $modelContent;
    }

    public function hasMarkerPair(string $content, string $marker, string $endMarker): bool
    {
        return str_contains($content, "// {$marker}") && str_contains($content, "// {$endMarker}");
    }

    private function replaceBetweenMarkers(string $content, string $marker, string $endMarker, string $newContent): string
    {
        $pattern = '/\/\/ ' . preg_quote($marker, '/') . '\n(.*?)\/\/ ' . preg_quote($endMarker, '/') . '/s';

        return preg_replace($pattern, "// {$marker}\n{$newContent}// {$endMarker}", $content);
    }

    private function insertMarkerBlock(string $content, string $section, string $blockContent): string
    {
        $marker = "// @dbml-sync:{$section}";
        $endMarker = "// @enddbml-sync:{$section}";
        $block = "\n    {$marker}\n{$blockContent}    {$endMarker}\n";

        return preg_replace('/(\n)(\s*\}\s*$)/m', $block . '$1$2', $content);
    }

    /**
     * @return array<string, string|null>
     */
    private function generateSections(Table $table, string $modelName, Schema $schema): array
    {
        $columns = $table->getColumns();
        $fillable = $this->modelBuilder->generateFillable($columns);
        $casts = $this->modelBuilder->generateCasts($columns);
        $relations = $this->modelBuilder->parseRelations(array_merge(
            $this->modelBuilder->generateBelongsToRelations($columns),
            $this->modelBuilder->generateHasManyRelations($table, $schema)
        ));
        $tableProperty = $this->modelBuilder->generateTableProperty($table->getName(), $modelName);

        $tab = str_repeat("\t", 2);

        $fillableString = '';
        if (! empty($fillable)) {
            $fillableString = "protected \$fillable = [\n{$tab}"
                . implode(",\n{$tab}", $fillable)
                . ",\n    ];\n";
        }

        $castsString = '';
        if (! empty($casts)) {
            $castsEntries = implode(",\n{$tab}", array_map(
                fn ($key, $value) => "'$key' => '$value'",
                array_keys($casts),
                $casts
            ));
            $castsString = "protected \$casts = [\n{$tab}{$castsEntries},\n    ];\n";
        }

        $relationString = '';
        if (! empty($relations)) {
            $relationString = $relations . "\n";
        }

        return [
            'table-property' => $tableProperty !== '' ? "    {$tableProperty}\n" : null,
            'fillable' => $fillableString !== '' ? "    {$fillableString}" : null,
            'casts' => $castsString !== '' ? "    {$castsString}" : null,
            'relations' => $relationString !== '' ? $relationString : null,
        ];
    }
}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `vendor/bin/pest tests/Unit/ModelPatcherTest.php`
Expected: PASS

- [ ] **Step 5: Commit**

```bash
git add src/Generation/ModelPatcher.php tests/Unit/ModelPatcherTest.php
git commit -m "feat: add ModelPatcher for @dbml-sync marker-based model patching"
```

---

## Task 8: Update model.stub with @dbml-sync markers

**Files:**
- Modify: `stubs/model.stub`

- [ ] **Step 1: Update model.stub to include markers**

```php
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class {{ modelName }} extends Model
{
    use HasFactory;

    // @dbml-sync:table-property
    {{ tableProperty }}
    // @enddbml-sync:table-property

    // @dbml-sync:fillable
    protected $fillable = [
        {{ fillable }}
    ];
    // @enddbml-sync:fillable

    // @dbml-sync:casts
    protected $casts = [
        {{ casts }}
    ];
    // @enddbml-sync:casts

    // @dbml-sync:relations
{{ relations }}
    // @enddbml-sync:relations
}
```

- [ ] **Step 2: Run existing feature tests to verify no regression**

Run: `vendor/bin/pest tests/Feature/GenerateFromDbmlCommandTest.php`
Expected: PASS (markers are comments, don't affect generated code)

- [ ] **Step 3: Update GenerateFromDbml::generateModelContent to handle marker-based output**

The current `generateModelContent` replaces stub placeholders. With markers in the stub, the `{{ tableProperty }}` placeholder now sits between markers. Ensure the placeholder replacement still works correctly. The `tableProperty` may be empty — replace with empty string, leaving just the marker lines.

In `ModelContentBuilder::formatModelContent()`, adjust the `$tableProperty` handling: when empty, output empty string (markers remain but with no content between them). This is already the default behavior — no code change needed if empty string replacement works correctly.

Verify by running tests. If `tableProperty` is empty and the output has `// @dbml-sync:table-property\n\n    // @enddbml-sync:table-property`, that's acceptable — empty marker sections.

- [ ] **Step 4: Commit**

```bash
git add stubs/model.stub
git commit -m "feat: add @dbml-sync markers to model stub"
```

---

## Task 9: Add snapshot write to GenerateFromDbml

**Files:**
- Modify: `src/Commands/GenerateFromDbml.php`

- [ ] **Step 1: Add snapshot write after generation loop**

In `src/Commands/GenerateFromDbml.php`, in the `handle()` method, after the foreach loop and before the success info line, add:

```php
// Write snapshot for future sync runs
$snapshotStore = new \Egyjs\DbmlToLaravel\Parsing\Dbml\JsonSnapshotStore;
$snapshotPayload = $parser->getLastPayload();
if ($snapshotPayload !== null) {
    $snapshotStore->write($file, $snapshotPayload);
}
```

**Problem:** `NodeDbmlParser::parse()` currently returns `Schema` and doesn't expose the raw JSON payload. Need to capture it.

**Solution:** Add `getLastPayload()` method to `NodeDbmlParser` that returns the raw array from the last `parse()` call.

In `src/Parsing/NodeDbmlParser.php`, add:

```php
private ?array $lastPayload = null;

public function parse(string $path): Schema
{
    // ... existing code ...

    $payload = json_decode($output, true, 512, JSON_THROW_ON_ERROR);

    // ... existing validation ...

    $this->lastPayload = $payload;

    return SchemaFactory::fromArray($payload);
}

public function getLastPayload(): ?array
{
    return $this->lastPayload;
}
```

Then in `GenerateFromDbml::handle()`, after parsing:

```php
$this->lastParserPayload = $parser->getLastPayload();
```

And after the generation loop:

```php
if ($this->lastParserPayload !== null) {
    (new JsonSnapshotStore)->write($file, $this->lastParserPayload);
}
```

- [ ] **Step 2: Run existing tests**

Run: `vendor/bin/pest tests/Feature/GenerateFromDbmlCommandTest.php`
Expected: PASS

- [ ] **Step 3: Commit**

```bash
git add src/Commands/GenerateFromDbml.php src/Parsing/NodeDbmlParser.php
git commit -m "feat: write snapshot after generate:dbml runs"
```

---

## Task 10: Implement SyncDbml command

**Files:**
- Create: `src/Commands/SyncDbml.php`
- Create: `tests/Feature/SyncDbmlCommandTest.php`
- Create: `tests/Fixtures/sync-base.dbml`
- Create: `tests/Fixtures/sync-modified.dbml`
- Create: `tests/Fixtures/sync-new-table.dbml`
- Modify: `src/DbmlToLaravelServiceProvider.php`

- [ ] **Step 1: Create sync test fixtures**

`tests/Fixtures/sync-base.dbml`:
```dbml
Table users {
  id int [pk, increment]
  name varchar
  email varchar [unique]
}

Table posts {
  id int [pk, increment]
  user_id "bigint unsigned" [ref: > users.id]
  title varchar
  content text
}

Ref: posts.user_id > users.id
```

`tests/Fixtures/sync-modified.dbml`:
```dbml
Table users {
  id int [pk, increment]
  name varchar
  email varchar [unique]
  phone varchar
}

Table posts {
  id int [pk, increment]
  user_id "bigint unsigned" [ref: > users.id]
  title varchar
}

Ref: posts.user_id > users.id
```

`tests/Fixtures/sync-new-table.dbml`:
```dbml
Table users {
  id int [pk, increment]
  name varchar
  email varchar [unique]
}

Table posts {
  id int [pk, increment]
  user_id "bigint unsigned" [ref: > users.id]
  title varchar
  content text
}

Table comments {
  id int [pk, increment]
  post_id "bigint unsigned" [ref: > posts.id]
  body text
}

Ref: posts.user_id > users.id
Ref: comments.post_id > posts.id
```

- [ ] **Step 2: Write failing feature test**

```php
<?php

declare(strict_types=1);

use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Filesystem\Filesystem;
use function Pest\Laravel\artisan;

it('generates alter migration from schema diff', function () {
    $filesystem = new Filesystem;
    $baseTempPath = base_path('tests/.tmp/'.uniqid('dbml_sync_', true));
    $appPath = $baseTempPath.'/app';
    $databasePath = $baseTempPath.'/database';

    $filesystem->makeDirectory($appPath.'/Models', 0755, true, true);
    $filesystem->makeDirectory($databasePath.'/migrations', 0755, true, true);

    $application = app();
    $originalAppPath = $application->path();
    $originalDatabasePath = $application->databasePath();

    $application->useAppPath($appPath);
    $application->useDatabasePath($databasePath);

    Carbon::setTestNow(Carbon::create(2026, 5, 16, 10));

    try {
        $baseFixture = __DIR__.'/../Fixtures/sync-base.dbml';
        $modifiedFixture = __DIR__.'/../Fixtures/sync-modified.dbml';

        // First, generate from base to create snapshot
        artisan('generate:dbml', ['file' => $baseFixture, '--force' => true])
            ->assertExitCode(Command::SUCCESS);

        // Verify snapshot was created
        $snapshotPath = dirname($baseFixture).'/.dbml-sync.json';
        expect(file_exists($snapshotPath))->toBeTrue();

        // Now sync with modified DBML
        artisan('dbml:sync', ['file' => $modifiedFixture])
            ->assertExitCode(Command::SUCCESS);

        // Verify alter migration was generated
        $migrationFiles = glob($databasePath.'/migrations/*sync*.php');
        expect($migrationFiles)->toHaveCount(1);

        $content = file_get_contents($migrationFiles[0]);
        expect($content)->toContain("Schema::table('users'");
        expect($content)->toContain("\$table->string('phone'");
    } finally {
        Carbon::setTestNow();
        $application->useAppPath($originalAppPath);
        $application->useDatabasePath($originalDatabasePath);
        $filesystem->deleteDirectory($baseTempPath);
    }
});

it('fails when no snapshot exists', function () {
    $fixture = __DIR__.'/../Fixtures/sync-modified.dbml';

    artisan('dbml:sync', ['file' => $fixture])
        ->expectsOutput('No snapshot found. Run generate:dbml first to create a snapshot.')
        ->assertExitCode(Command::FAILURE);
});

it('reports no changes when schemas are identical', function () {
    $filesystem = new Filesystem;
    $baseTempPath = base_path('tests/.tmp/'.uniqid('dbml_sync_nochanges_', true));
    $appPath = $baseTempPath.'/app';
    $databasePath = $baseTempPath.'/database';

    $filesystem->makeDirectory($appPath.'/Models', 0755, true, true);
    $filesystem->makeDirectory($databasePath.'/migrations', 0755, true, true);

    $application = app();
    $originalAppPath = $application->path();
    $originalDatabasePath = $application->databasePath();

    $application->useAppPath($appPath);
    $application->useDatabasePath($databasePath);

    try {
        $baseFixture = __DIR__.'/../Fixtures/sync-base.dbml';

        artisan('generate:dbml', ['file' => $baseFixture, '--force' => true])
            ->assertExitCode(Command::SUCCESS);

        artisan('dbml:sync', ['file' => $baseFixture])
            ->expectsOutput('No schema changes detected.')
            ->assertExitCode(Command::SUCCESS);
    } finally {
        $application->useAppPath($originalAppPath);
        $application->useDatabasePath($originalDatabasePath);
        $filesystem->deleteDirectory($baseTempPath);
    }
});
```

- [ ] **Step 3: Run test to verify it fails**

Run: `vendor/bin/pest tests/Feature/SyncDbmlCommandTest.php`
Expected: FAIL — command `dbml:sync` not registered

- [ ] **Step 4: Implement SyncDbml command**

```php
<?php

declare(strict_types=1);

namespace Egyjs\DbmlToLaravel\Commands;

use Egyjs\DbmlToLaravel\Generation\AlterMigrationGenerator;
use Egyjs\DbmlToLaravel\Generation\ColumnDefinitionBuilder;
use Egyjs\DbmlToLaravel\Generation\ModelContentBuilder;
use Egyjs\DbmlToLaravel\Generation\ModelPatcher;
use Egyjs\DbmlToLaravel\Parsing\Dbml\JsonSnapshotStore;
use Egyjs\DbmlToLaravel\Parsing\Dbml\SchemaDiffer;
use Egyjs\DbmlToLaravel\Parsing\NodeDbmlParser;
use Illuminate\Console\Command;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Str;
use Throwable;

class SyncDbml extends Command
{
    protected $signature = 'dbml:sync {file} {--force : Skip confirmation prompts}';

    protected $description = 'Generate incremental migrations and update models by syncing DBML changes';

    private int $migrationCounter = 0;

    public function handle(): int
    {
        $file = $this->argument('file');

        if (! file_exists($file)) {
            $this->error("File not found: $file");

            return static::FAILURE;
        }

        $snapshotStore = new JsonSnapshotStore;

        if (! $snapshotStore->exists($file)) {
            $this->error('No snapshot found. Run generate:dbml first to create a snapshot.');

            return static::FAILURE;
        }

        try {
            $parser = new NodeDbmlParser;
            $newSchema = $parser->parse($file);
            $payload = $parser->getLastPayload();
        } catch (Throwable $e) {
            $this->error('Failed to parse DBML file: '.$e->getMessage());

            return static::FAILURE;
        }

        $oldSchema = $snapshotStore->read($file);

        if ($oldSchema === null) {
            $this->error('Failed to read snapshot. Delete .dbml-sync.json and re-run generate:dbml.');

            return static::FAILURE;
        }

        $diff = SchemaDiffer::diff($oldSchema, $newSchema);

        if ($diff->isEmpty()) {
            $this->info('No schema changes detected.');

            return static::SUCCESS;
        }

        $this->displayDiffSummary($diff);

        if (! empty($diff->droppedTables) && ! $this->option('force')) {
            if (! $this->confirm('Dropped tables detected. This will generate DROP TABLE migrations. Continue?')) {
                $this->info('Sync cancelled.');

                return static::SUCCESS;
            }
        }

        $enums = $newSchema->getEnums();
        $columnBuilder = new ColumnDefinitionBuilder($enums);
        $modelBuilder = new ModelContentBuilder;
        $alterGenerator = new AlterMigrationGenerator($columnBuilder);
        $modelPatcher = new ModelPatcher($modelBuilder);

        $generatedMigrations = 0;
        $patchedModels = 0;

        $this->migrationCounter = 0;

        // Handle new tables
        foreach ($diff->addedTables as $table) {
            $this->generateCreateMigration($table, $columnBuilder);
            $generatedMigrations++;
        }

        // Handle modified tables
        foreach ($diff->modifiedTables as $tableDiff) {
            if (! $tableDiff->isEmpty()) {
                $this->generateAlterMigration($tableDiff, $alterGenerator);
                $generatedMigrations++;
            }

            $modelName = Str::studly(Str::singular($tableDiff->tableName));
            $modelPath = app_path("Models/$modelName.php");

            if (file_exists($modelPath)) {
                $modelContent = file_get_contents($modelPath);
                $table = $this->findTable($newSchema, $tableDiff->tableName);

                if ($table !== null) {
                    $patched = $modelPatcher->patch($modelContent, $table, $modelName, $newSchema);

                    if (! $this->option('force')) {
                        $this->info("Model changes for {$modelName}:");
                        $this->line("  - Updated fillable, casts, and relations within @dbml-sync markers");

                        if (! $this->confirm("Apply model changes to {$modelName}?")) {
                            continue;
                        }
                    }

                    (new Filesystem)->put($modelPath, $patched);
                    $this->info("Model {$modelName} patched.");
                    $patchedModels++;
                }
            }
        }

        // Handle dropped tables
        foreach ($diff->droppedTables as $tableName) {
            $this->generateDropMigration($tableName);
            $generatedMigrations++;
        }

        // Update snapshot
        $snapshotStore->write($file, $payload ?? []);

        $this->info("Synced: $generatedMigrations migrations generated, $patchedModels models patched.");

        return static::SUCCESS;
    }

    private function displayDiffSummary(SchemaDiff $diff): void
    {
        foreach ($diff->addedTables as $table) {
            $this->info("+ Table {$table->getName()} (new)");
        }

        foreach ($diff->droppedTables as $tableName) {
            $this->warn("- Table {$tableName} (dropped)");
        }

        foreach ($diff->modifiedTables as $tableDiff) {
            $changes = [];

            if (! empty($tableDiff->addedColumns)) {
                $names = array_map(fn ($col) => $col->getName(), $tableDiff->addedColumns);
                $changes[] = 'added columns: '.implode(', ', $names);
            }

            if (! empty($tableDiff->droppedColumns)) {
                $names = array_map(fn ($col) => $col->getName(), $tableDiff->droppedColumns);
                $changes[] = 'dropped columns: '.implode(', ', $names);
            }

            if (! empty($tableDiff->modifiedColumns)) {
                $names = array_map(fn ($pair) => $pair->new->getName(), $tableDiff->modifiedColumns);
                $changes[] = 'modified columns: '.implode(', ', $names);
            }

            if (! empty($tableDiff->addedIndexes)) {
                $changes[] = count($tableDiff->addedIndexes).' added index(es)';
            }

            if (! empty($tableDiff->droppedIndexes)) {
                $changes[] = count($tableDiff->droppedIndexes).' dropped index(es)';
            }

            if (! empty($tableDiff->addedForeignKeys)) {
                $changes[] = count($tableDiff->addedForeignKeys).' added FK(s)';
            }

            if (! empty($tableDiff->droppedForeignKeys)) {
                $changes[] = count($tableDiff->droppedForeignKeys).' dropped FK(s)';
            }

            $this->line("~ Table {$tableDiff->tableName}: ".implode('; ', $changes));
        }
    }

    private function generateCreateMigration(Table $table, ColumnDefinitionBuilder $columnBuilder): void
    {
        $baseDate = now()->format('Y_m_d');
        $sequence = str_pad((string) $this->migrationCounter, 6, '0', STR_PAD_LEFT);
        $timestamp = $baseDate.'_'.$sequence;
        $migrationName = 'create_'.Str::snake($table->getName()).'_table';
        $fileName = $timestamp.'_'.$migrationName.'.php';
        $filePath = database_path("migrations/$fileName");

        $columnDefinitions = collect($table->getColumns())
            ->map(fn ($column) => $columnBuilder->buildColumnDefinition($column))
            ->implode("\n");

        $indexDefinitions = collect($table->getIndexes())
            ->map(fn ($index) => $columnBuilder->buildIndexDefinition($index))
            ->filter()
            ->implode("\n");

        if ($indexDefinitions !== '') {
            $indexDefinitions = "\n".$indexDefinitions;
        }

        $stub = file_exists(base_path('stubs/dbml-to-laravel/migration.stub'))
            ? file_get_contents(base_path('stubs/dbml-to-laravel/migration.stub'))
            : file_get_contents(__DIR__.'/../../stubs/migration.stub');

        $content = str_replace(
            ['{{ tableName }}', '{{ columns }}', '{{ indexes }}'],
            [$table->getName(), $columnDefinitions, $indexDefinitions],
            $stub
        );

        $this->ensureDirectoryExists(dirname($filePath));
        (new Filesystem)->put($filePath, $content);
        $this->info("Migration for new table {$table->getName()} created.");
        $this->migrationCounter++;
    }

    private function generateAlterMigration(TableDiff $tableDiff, AlterMigrationGenerator $generator): void
    {
        $baseDate = now()->format('Y_m_d');
        $sequence = str_pad((string) $this->migrationCounter, 6, '0', STR_PAD_LEFT);
        $timestamp = $baseDate.'_'.$sequence;
        $migrationName = 'sync_'.$tableDiff->tableName;
        $fileName = $timestamp.'_'.$migrationName.'.php';
        $filePath = database_path("migrations/$fileName");

        $stubPath = base_path('stubs/dbml-to-laravel/alter-migration.stub');
        if (! file_exists($stubPath)) {
            $stubPath = __DIR__.'/../../stubs/alter-migration.stub';
        }

        $stubContent = file_get_contents($stubPath);
        $content = $generator->generate($tableDiff, $stubContent);

        $this->ensureDirectoryExists(dirname($filePath));
        (new Filesystem)->put($filePath, $content);
        $this->info("Alter migration for {$tableDiff->tableName} created.");
        $this->migrationCounter++;
    }

    private function generateDropMigration(string $tableName): void
    {
        $baseDate = now()->format('Y_m_d');
        $sequence = str_pad((string) $this->migrationCounter, 6, '0', STR_PAD_LEFT);
        $timestamp = $baseDate.'_'.$sequence;
        $migrationName = 'drop_'.Str::snake($tableName).'_table';
        $fileName = $timestamp.'_'.$migrationName.'.php';
        $filePath = database_path("migrations/$fileName");

        $content = <<<'PHP'
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::dropIfExists('{{ tableName }}');
    }

    public function down(): void
    {
        // Cannot recreate dropped table automatically
    }
};
PHP;

        $content = str_replace('{{ tableName }}', $tableName, $content);

        $this->ensureDirectoryExists(dirname($filePath));
        (new Filesystem)->put($filePath, $content);
        $this->warn("Drop migration for {$tableName} created.");
        $this->migrationCounter++;
    }

    private function findTable(Schema $schema, string $tableName): ?Table
    {
        foreach ($schema->getTables() as $table) {
            if (strtolower($table->getName()) === strtolower($tableName)) {
                return $table;
            }
        }

        return null;
    }

    private function ensureDirectoryExists(string $directory): void
    {
        if (! is_dir($directory)) {
            mkdir($directory, 0755, true);
        }
    }
}
```

- [ ] **Step 5: Register SyncDbml in ServiceProvider**

In `src/DbmlToLaravelServiceProvider.php`, add:

```php
use Egyjs\DbmlToLaravel\Commands\SyncDbml;

// In configurePackage():
->hasCommand(SyncDbml::class)
```

- [ ] **Step 6: Run feature test**

Run: `vendor/bin/pest tests/Feature/SyncDbmlCommandTest.php`
Expected: PASS

- [ ] **Step 7: Run full test suite**

Run: `vendor/bin/pest`
Expected: All PASS

- [ ] **Step 8: Commit**

```bash
git add src/Commands/SyncDbml.php tests/Feature/SyncDbmlCommandTest.php tests/Fixtures/sync-base.dbml tests/Fixtures/sync-modified.dbml tests/Fixtures/sync-new-table.dbml src/DbmlToLaravelServiceProvider.php
git commit -m "feat: add dbml:sync command for incremental migrations"
```

---

## Task 11: Update README.md

**Files:**
- Modify: `README.md`

- [ ] **Step 1: Add dbml:sync section to README**

Add after the "Usage" section:

```markdown
### Incremental Sync

After initial generation, modify your DBML schema and run the sync command to generate incremental ALTER migrations and update your models:

```bash
php artisan dbml:sync database/schema.dbml
```

The sync command compares your updated DBML against a snapshot (`.dbml-sync.json`) created during the initial `generate:dbml` run, then generates:
- **ALTER migrations** for added, dropped, or modified columns
- **Model patches** for updated fillable, casts, and relations (within `@dbml-sync` markers)

Use `--force` to skip confirmation prompts:

```bash
php artisan dbml:sync database/schema.dbml --force
```

**Note:** Column modifications using `->change()` require `doctrine/dbal`. Install it with `composer require doctrine/dbal`.
```

- [ ] **Step 2: Update features list**

Add to the features section:

```markdown
* **Incremental Schema Sync:** Generate ALTER migrations by comparing your updated DBML against a snapshot — no need to overwrite base migrations.
```

- [ ] **Step 3: Commit**

```bash
git add README.md
git commit -m "docs: document dbml:sync command in README"
```

---

## Self-Review

### Spec coverage check

| Spec section | Task |
|-------------|------|
| DRY extraction | Task 1, Task 2 |
| SchemaDiff/TableDiff/ColumnPair | Task 3 |
| SchemaDiffer | Task 4 |
| SnapshotStore/JsonSnapshotStore | Task 5 |
| Alter migration stub + AlterMigrationGenerator | Task 6 |
| ModelPatcher with markers | Task 7 |
| Model stub markers | Task 8 |
| Snapshot write in generate:dbml | Task 9 |
| SyncDbml command + tests + fixtures | Task 10 |
| README docs | Task 11 |

### Placeholder scan

No TBD/TODO/"implement later" patterns found. All steps contain actual code.

### Type consistency

- `ColumnDefinitionBuilder` methods match between builder and `AlterMigrationGenerator` consumer
- `ModelContentBuilder` methods match between builder and `ModelPatcher` consumer
- `TableDiff.addedForeignKeys` / `droppedForeignKeys` type changed to `array{column: string, reference: ColumnReference}[]` — consistent across `SchemaDiffer`, `AlterMigrationGenerator`, and `SyncDbml`
- `NodeDbmlParser::getLastPayload()` return type `?array` matches usage in `GenerateFromDbml` and `SyncDbml`