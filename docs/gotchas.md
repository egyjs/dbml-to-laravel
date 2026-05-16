Read this when something silently fails, produces unexpected output, or you hit a compatibility wall.

## Compatibility Matrix

| Constraint | Range | Source |
|-----------|-------|--------|
| PHP | 8.0 – 8.4 | `composer.json` `require.php` |
| Laravel | 8 – 13 | `composer.json` `illuminate/*` constraints |
| Node.js | 18+ (runtime only) | `package.json` esbuild target |
| Pest | 1, 2, 3 | `composer.json` `require-dev` |

### What this means for code

- Avoid PHP 8.1+ syntax (`enum`, `readonly`, `never` return type) unless gated by `version_compare` or polyfill
- Avoid Laravel APIs added after 8.x without checking — wide constraint targets oldest supported version
- CI only tests PHP 8.2–8.4 + Laravel 11–12 (`.github/workflows/run-tests.yml`) — older combos rely on `prefer-lowest` stability

## Silent Drops

These cases produce no error and no output — the feature is silently skipped.

### Many-to-many relationships

DBML `*:*` refs are dropped by `SchemaFactory::determineDirection()` (`src/Parsing/Dbml/SchemaFactory.php:129`). Only `1:*` (one-to-many) is supported. The method returns `null` for unsupported relations, and `attachReferences()` skips the ref entirely.

Result: no `belongsToMany` method generated, no pivot table detected.

### Unsupported foreign-key actions

`mapForeignAction()` (`src/Commands/GenerateFromDbml.php:553`) only maps:

- `cascade` → `->cascadeOnDelete()` / `->cascadeOnUpdate()`
- `restrict` → `->restrictOnDelete()` / `->restrictOnUpdate()`
- `set null` → `->nullOnDelete()` / `->nullOnUpdate()`

Any other `onDelete`/`onUpdate` value (e.g. `no action`, `set default`) returns empty string — silently dropped from migration output.

### Forbidden model names

Tables whose studly-singular name matches a PHP reserved word are skipped entirely. The list in `FORBIDDEN_MODEL_NAMES` (`src/Commands/GenerateFromDbml.php:31`):

```
Class, Trait, Interface, Namespace, Object, Resource, String,
Array, Float, Int, Bool, Boolean, Null, Void, Iterable,
Parent, Self, Static, Mixed
```

Both `generateModel()` and `generateMigration()` return `false` independently — but the `$migrationCounter` increments only on successful migration write, so ordering stays consistent.

## Quirks

### Migration counter only increments on success

`$migrationCounter` increments after `generateMigration()` writes the file (`src/Commands/GenerateFromDbml.php:144`). If migration is skipped (file exists, forbidden name), counter does not advance. This means timestamp gaps are possible but ordering is preserved relative to DBML table order.

### Case-insensitive column keys

`SchemaFactory::columnKey()` lowercases `schema.table.column` for lookup (`src/Parsing/Dbml/SchemaFactory.php:124`). DBML column names `UserID` and `userid` collide. Refs attach to the first column inserted with that key.

### `foreignId()` vs explicit foreign key

`buildColumnDefinition()` (`src/Commands/GenerateFromDbml.php:395`) uses `foreignId()->constrained()` only when the referencing column type is `bigint unsigned` or empty string. All other types (including `int unsigned`, `varchar`) get explicit `$table->foreign()` syntax.

This matters: `int unsigned` FK gets `unsignedInteger()` column + separate `->foreign()` line, while `bigint unsigned` FK gets `foreignId()->constrained()` (Laravel sugar).

### Table property only when non-standard

`generateTableProperty()` (`src/Commands/GenerateFromDbml.php:591`) emits `protected $table = 'name'` only when the DBML table name diverges from Laravel's expected `Str::snake(Str::plural($modelName))`. Standard names produce no table property.

### Casts filter out defaults

`mapCastType()` returns `''` (empty) for types that map to Laravel's default casts (`string` → empty, `integer` → `'integer'` which is then filtered). The `generateCasts()` method (`src/Commands/GenerateFromDbml.php:291`) filters out both empty values AND `'string'`/`'integer'` — so `integer` columns never appear in `$casts`.

### Default values with DB::raw()

Expression defaults (e.g. `now()`) use `DB::raw()` in migration output. The migration stub includes `use Illuminate\Support\Facades\DB;` for this reason. Removing that import breaks expression defaults.

### Indentation is hardcoded

Fillable/casts indentation in models is hardcoded as 2 tabs in `generateModelContent()` (`src/Commands/GenerateFromDbml.php:169`). Column definition indentation in migrations is 12 spaces (3 levels) in `buildColumnDefinition()`. Changing indentation requires editing PHP, not stubs.