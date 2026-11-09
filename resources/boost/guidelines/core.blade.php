## DBML to Laravel (`egyjs/dbml-to-laravel`)

This application uses [`egyjs/dbml-to-laravel`](https://github.com/egyjs/dbml-to-laravel) to generate and synchronize Laravel Eloquent models and database migrations from a DBML (Database Markup Language) schema file.

### Core Conventions for AI Agents

- **DBML is the Single Source of Truth**: When adding, modifying, or removing database tables, columns, indexes, or foreign key relationships, edit the project's `.dbml` schema file (typically `database/schema.dbml`) instead of manually running `php artisan make:migration` or `php artisan make:model`.
- **Initial Schema Generation**: If `.dbml-sync.json` does not exist yet, generate models, migrations, and the initial snapshot with:
@verbatim
<code-snippet name="Generate models and migrations from DBML" lang="bash">
php artisan dbml:generate database/schema.dbml
</code-snippet>
@endverbatim
- **Incremental Schema Synchronization**: After editing the `.dbml` schema file in an existing project, synchronize changes using `dbml:sync` with `--force` (to avoid interactive confirmation prompts):
@verbatim
<code-snippet name="Incrementally sync DBML schema changes" lang="bash">
php artisan dbml:sync database/schema.dbml --force
</code-snippet>
@endverbatim
- **Protect `@dbml-sync` Model Markers**: `dbml:sync` patches Eloquent models inside `// @dbml-sync:*` and `// @enddbml-sync:*` marker blocks (`table-property`, `fillable`, `casts`, `relations`). Never delete these marker comments, and always add custom model methods, scopes, traits, or attributes outside of those marker blocks.
