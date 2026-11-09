---
name: dbml-to-laravel
description: Generate and incrementally synchronize Laravel Eloquent models and database migrations from DBML schema files using egyjs/dbml-to-laravel.
---

# DBML to Laravel Development

## When to use this skill
Use this skill whenever creating or modifying database tables, columns, indexes, foreign keys, or Eloquent model relationships in a project that has [`egyjs/dbml-to-laravel`](https://github.com/egyjs/dbml-to-laravel) installed.

## Workflow

1. **Locate the DBML Schema**: Check for `database/schema.dbml` (or any `.dbml` file in `database/`). The `.dbml-sync.json` snapshot lives **next to the DBML file** (e.g. `database/.dbml-sync.json`).
2. **Edit the DBML File**: Define tables, column types, attributes (`pk`, `increment`, `unique`, `not null`, `default`, `note`), and relationships.
   - Declare each relationship once — inline `[ref: > users.id]` **or** a top-level `Ref:` line, never both (duplicates fail to parse).
   - Only `>` / `<` refs generate foreign keys and `belongsTo` / `hasMany`. One-to-one (`-`) and many-to-many (`<>`) refs are ignored; write pivot tables and `hasOne` / `belongsToMany` by hand.
   - Columns without `not null` are generated as `->nullable()`.
3. **Run the Generator or Incremental Sync**:
   - First-time generation (no `.dbml-sync.json` present):
     ```bash
     php artisan dbml:generate database/schema.dbml
     ```
   - Incremental update (when `.dbml-sync.json` exists):
     ```bash
     php artisan dbml:sync database/schema.dbml --force
     ```
4. **Review Generated Artifacts**:
   - Verify the newly generated migration in `database/migrations/`.
   - Verify patched Eloquent models in `app/Models/`.

## Working with Eloquent Model Markers

Generated models contain `@dbml-sync` markers targeted by `dbml:sync`:

```php
class User extends Model
{
    // @dbml-sync:fillable
    protected $fillable = ['name', 'email'];
    // @enddbml-sync:fillable

    // @dbml-sync:casts
    protected $casts = ['email_verified_at' => 'datetime'];
    // @enddbml-sync:casts

    // @dbml-sync:relations
    public function posts()
    {
        return $this->hasMany(Post::class);
    }
    // @enddbml-sync:relations

    // Place custom methods, scopes, and accessors outside @dbml-sync markers
}
```

- Code outside `@dbml-sync` markers is never overwritten by `php artisan dbml:sync`.
- To customize generated code structure project-wide, publish and edit the stubs in `stubs/dbml-to-laravel/`:
  ```bash
  php artisan vendor:publish --tag=dbml-to-laravel-stubs
  ```
