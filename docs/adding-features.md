Read this when you need to add a column type, cast, foreign-key action, relationship, or stub to the generator.

# Adding Features

Recipe-style guides for extending `generate:dbml`. Each recipe: goal, files to edit, step-by-step instructions, what to test.

## 1. Add a new column type mapping

**Goal:** Make `generate:dbml` recognize a new DBML column type and emit the correct Laravel migration method.

**Files:** `src/Commands/GenerateFromDbml.php`

**Steps:**

1. Open `resolveColumnBaseDefinition()` (line ~456). Find the `match ($type)` block (line ~483).
2. Add a new arm: `'your_type' => "\$table->yourMethod('$name')"`. Use `$name` for the column name, `$args[0]` etc. for type arguments.
3. If the type needs a cast, also add an arm in `mapCastType()` (line ~570, `match ($type)` at line ~575).
4. If the type is an unsigned variant, check `buildColumnDefinition()` (line ~389) -- `foreignId` auto-detection at line ~395 uses `bigint unsigned` or empty type.
5. Add a test case in `tests/Fixtures/` with a table using the new type. Run `composer test`.

**Example -- adding `money` type:**

```php
// In resolveColumnBaseDefinition(), add to match:
'money' => "\$table->decimal('$name', 10, 2)",
```

## 2. Add a new cast mapping

**Goal:** Map a DBML column type to an Eloquent cast.

**Files:** `src/Commands/GenerateFromDbml.php`

**Steps:**

1. Open `mapCastType()` (line ~570).
2. Add an arm to the `match ($type)` block (line ~575): `'your_type' => 'castName'`.
3. Returning `'string'` or `'integer'` gets filtered out because those are Laravel defaults. Only add non-default casts.
4. Test: add a column with that type in a fixture DBML, run the command, check the model `$casts` array.

## 3. Add a new foreign-key action

**Goal:** Support an `onDelete`/`onUpdate` action not currently mapped (currently only `cascade`, `restrict`, `set null`).

**Files:** `src/Commands/GenerateFromDbml.php`

**Steps:**

1. Open `mapForeignAction()` (line ~553).
2. Add a new `match` arm at line ~557: `'your_action' => "->yourOn{OperationMethod}()"`.
3. `{OperationMethod}` is `ucfirst($operation)` -- either `Delete` or `Update`.
4. Currently unmatched actions silently return an empty string (dropped). Your new arm prevents that.
5. Test: add a ref with the new action in a fixture, verify migration output.

## 4. Add a new Eloquent relationship type

**Goal:** Generate a new relationship method (e.g. `belongsToMany`).

**Files:** `src/Commands/GenerateFromDbml.php`

**Steps:**

1. `generateBelongsToRelations()` (line ~295) builds `belongsTo` from columns with refs.
2. `generateHasManyRelations()` (line ~315) scans all tables for back-references.
3. `formatRelationBody()` (line ~364) renders the method body -- add a new `match` arm for your type.
4. `parseRelations()` (line ~348) renders all relation methods into model content.
5. Note: many-to-many (`*:*`) is currently dropped by `SchemaFactory::determineDirection()` -- see `src/Parsing/Dbml/SchemaFactory.php:129`. Supporting `belongsToMany` requires fixing direction detection first.
6. See [architecture.md](architecture.md) for the full pipeline context.

## 5. Modify stubs

See [stubs.md](stubs.md) for stub customization details.

## 6. Change default column length/precision

**Goal:** Adjust default length for `varchar`, precision for `decimal`, etc.

**Files:** `src/Commands/GenerateFromDbml.php`

**Steps:**

1. Open `resolveColumnBaseDefinition()` (line ~456).
2. Defaults are defined at lines ~478-481: `$stringLength`, `$charLength`, `$precision`, `$scale`.
3. Change `255` for string length, `8` for decimal precision, `2` for decimal scale.
4. These use `max(1, (int) ($args[0] ?? default))` -- DBML args override defaults when provided.