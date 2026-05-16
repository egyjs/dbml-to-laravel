Read this when you need to change how generated models or migrations look -- placeholders, overrides, indentation.

# Stubs

## Stub Placeholders

Two stubs: `stubs/model.stub` and `stubs/migration.stub`. Simple string replace -- no template engine.

### model.stub placeholders

| Placeholder | Replaced with | Generator method |
|-------------|--------------|------------------|
| `{{ modelName }}` | Studly singular model name | `generateModelContent()` |
| `{{ tableProperty }}` | `protected $table = 'name';` or empty string | `generateTableProperty()` |
| `{{ fillable }}` | Comma-separated `'col'` values, 2-tab indented | `generateFillable()` |
| `{{ casts }}` | `'col' => 'type'` pairs, 2-tab indented | `generateCasts()` |
| `{{ relations }}` | Method definitions (belongsTo, hasMany) | `parseRelations()` |

### migration.stub placeholders

| Placeholder | Replaced with | Generator method |
|-------------|--------------|------------------|
| `{{ tableName }}` | DBML table name | `generateMigrationContent()` |
| `{{ columns }}` | `$table->...` lines with 12-space indent | `generateMigrationColumns()` |
| `{{ indexes }}` | `$table->index/unique(...)` lines or empty | `generateIndexDefinitions()` |

## Stub Override Path

Package stubs live in `stubs/`. Users can publish them:

```bash
php artisan vendor:publish --tag=dbml-to-laravel-stubs
```

This copies stubs to `stubs/dbml-to-laravel/` in the Laravel app root.

`GenerateFromDbml::getStubContent()` checks **published path first** (`base_path('stubs/dbml-to-laravel/<name>')`), then falls back to package stubs (`__DIR__/../../stubs/<name>`).

## Customization Tips

- Published stubs survive `composer update`. Package stubs get overwritten.
- Indentation inside fillable/casts is hardcoded as 2 tabs in `generateModelContent()` (line ~169: `$tab = str_repeat("\t", 2)`). Changing indentation requires editing PHP, not stubs.
- Column definitions in migrations use 12-space indent (3 levels of 4-space), hardcoded in `buildColumnDefinition()`.
- Stubs include `use Illuminate\Support\Facades\DB;` in the migration stub -- needed for `DB::raw()` default values.

## Related

- [adding-features.md](adding-features.md) -- recipe guides for extending column types, casts, actions, and relationships.