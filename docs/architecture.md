Read this when you need to understand how a `.dbml` file becomes Eloquent models and migrations.

## Three-Stage Pipeline

**DBML file -> Node parser -> PHP schema model -> stub-based code generation**

```
 .dbml file
     |
     v  (1) Node parser
 +----------------------+
 |  bin/parse-dbml.js   |--@dbml/core Parser--> JSON {tables, enums, refs}
 |  .runtime.cjs bundle |
 +----------------------+
     | stdout
     v
 +----------------------+
 |  NodeDbmlParser.php  |  reads JSON, throws RuntimeException on stderr/malformed
 +----------------------+
     | SchemaFactory::fromArray()
     v
 +----------------------+
 |  Schema object graph |  Schema -> Table[] -> Column[] (with ColumnType, ColumnDefaultValue, ColumnReference[])
 |                       |  + IndexDefinition[] per table, EnumDefinition[] on schema
 +----------------------+
     |
     v  (3) Code generation
 +--------------------------+
 |  GenerateFromDbml.php    |--stub replace--> Model .php + Migration .php
 |  (monolithic command)    |
 +--------------------------+
```

---

## Stage 1 -- DBML Parsing (Node)

- `src/Parsing/NodeDbmlParser.php` spawns `node bin/parse-dbml.runtime.cjs <file>` via `symfony/process`
- Falls back to `bin/parse-dbml.js` if `.runtime.cjs` missing
- Node script uses `@dbml/core` `Parser`, emits JSON on stdout: `{ tables, enums, refs }`
- PHP decodes JSON, throws `RuntimeException` on stderr or malformed output
- See [parser-bundle.md](parser-bundle.md) for bundle workflow

---

## Stage 2 -- Schema Model (PHP)

`src/Parsing/Dbml/SchemaFactory::fromArray()` builds the object graph from the JSON payload.

### Key classes (`src/Parsing/Dbml/`)

| Class | Role |
|-------|------|
| `Schema` | Root container: `Table[]`, `EnumDefinition[]` |
| `Table` | `name`, `schema`, `Column[]`, `IndexDefinition[]` |
| `Column` | `name`, `ColumnType`, `primaryKey`, `unique`, `notNull`, `autoIncrement`, `ColumnDefaultValue`, `ColumnReference[]` |
| `ColumnType` | `name`, `schemaName`, `args[]` |
| `ColumnReference` | `referencedTable`, `referencedColumn`, `onDelete`, `onUpdate` |
| `ColumnDefaultValue` | `value`, `isExpression` |
| `IndexDefinition` | `name`, `columns[]`, `unique`, `type` |
| `EnumDefinition` | `name`, `EnumValue[]` |

### Foreign keys

Foreign keys are **not** stored on `Table`. They are attached to the **referencing** `Column` via `addReference()`.

`SchemaFactory::determineDirection()` reads the DBML endpoint `relation` field (`'*'` vs `'1'`) to decide which side is referencing vs referenced. Unsupported relations (e.g. many-to-many `*:*`) are silently dropped -- the method returns `null`.

### Column lookup

Column lookup uses `strtolower(schema.table.column)` keys -- case-insensitive matching against DBML.

---

## Stage 3 -- Code Generation

`src/Commands/GenerateFromDbml.php` is a monolithic command handling both model and migration generation.

### Stub resolution

Checks `base_path('stubs/dbml-to-laravel/<name>')` first. Published stubs override the package defaults in `stubs/`. See [stubs.md](stubs.md).

### Migration ordering

`$migrationCounter` is incremented per table and appended to today's date, producing timestamps like `Y_m_d_000000`, `Y_m_d_000001`, etc. This preserves DBML table order so foreign-key dependencies resolve correctly at migration time. Without `--force`, existing `*_create_<table>_table.php` files cause that table to be skipped.

### Foreign keys in migrations

- `bigint unsigned` or empty column type uses Laravel sugar: `foreignId()->constrained()`
- Otherwise emits explicit `$table->foreign()->references()->on()` after the column line
- `onDelete`/`onUpdate` map only `cascade`, `restrict`, `set null` -- other actions silently dropped
- See [adding-features.md](adding-features.md) for adding new FK actions

### Auto-increment primary keys

PK + autoIncrement maps to `increments` / `bigIncrements` / `smallIncrements` (type-aware). Non-auto PK gets a `->primary()` suffix instead.

### Forbidden model names

PHP reserved words listed in `FORBIDDEN_MODEL_NAMES` cause the table to be skipped entirely -- no model AND no migration generated. Note: the migration counter still increments consistently because both `generateModel` and `generateMigration` return `false` independently.

### Relations

- `belongsTo` generated from columns that carry references
- `hasMany` discovered by scanning all tables for back-references pointing to the current model
- Many-to-many relations are not generated

### Casts

`mapCastType()` filters out `string` and `integer` from `$casts` because those are Laravel defaults and would be redundant.

### Table property

The `protected $table` property is only emitted in the model when the DBML table name diverges from `Str::snake(Str::plural($modelName))`.

---

## Key Entry Points

| File | Responsibility |
|------|---------------|
| `src/DbmlToLaravelServiceProvider.php` | Registers `GenerateFromDbml` command, publishes stubs |
| `src/Commands/GenerateFromDbml.php` | Monolithic: orchestrate parse -> model gen -> migration gen |
| `src/Parsing/NodeDbmlParser.php` | Shell out to Node, decode JSON, return Schema |
| `src/Parsing/Dbml/SchemaFactory.php` | Build Schema object graph from JSON payload |
| `bin/parse-dbml.js` | Node script: parse DBML -> JSON |
| `bin/parse-dbml.runtime.cjs` | esbuild bundle of above (committed artifact) |