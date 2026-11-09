# **DBML to Laravel Eloquent Generator ✨**


**Generate Laravel Eloquent models and migration files directly from your DBML (Database Markup Language) diagrams, accelerating Laravel development and streamlining your schema-to-code workflow.**

> 📰 **Featured on [Laravel News](https://laravel-news.com/dbml-to-laravel)** — the official community blog for Laravel developers!

[![Latest Version on Packagist](https://img.shields.io/packagist/v/egyjs/dbml-to-laravel.svg?style=flat-square)](https://packagist.org/packages/egyjs/dbml-to-laravel)
[![GitHub Tests Action Status](https://img.shields.io/github/actions/workflow/status/egyjs/dbml-to-laravel/run-tests.yml?branch=main&label=tests&style=flat-square)](https://github.com/egyjs/dbml-to-laravel/actions?query=workflow%3Arun-tests+branch%3Amain)
[![Total Downloads](https://img.shields.io/packagist/dt/egyjs/dbml-to-laravel.svg?style=flat-square)](https://packagist.org/packages/egyjs/dbml-to-laravel)
[![Featured on Laravel News](https://img.shields.io/badge/Laravel%20News-Featured-orange?style=flat-square)](https://laravel-news.com/dbml-to-laravel)

## **⚡ Quick Start**

```bash
composer require egyjs/dbml-to-laravel --dev
php artisan dbml:generate database/schema.dbml        # first run: models + migrations + snapshot
php artisan dbml:sync database/schema.dbml --force    # after each DBML edit: incremental migrations
```

## **🎯 When to Use This Package**

Use `egyjs/dbml-to-laravel` when you want to:

* **Turn a DBML schema or [dbdiagram.io](https://dbdiagram.io) diagram into Laravel code** — Eloquent models and migrations in one command.
* **Keep the database schema in one declarative file** (`database/schema.dbml`) and generate migrations from changes, instead of hand-writing `php artisan make:migration` files.
* **Scaffold a new Laravel app's data layer quickly** — fillable, casts, `belongsTo`/`hasMany` relations, foreign keys, indexes and enums.
* **Let AI coding agents change the schema safely** — agents edit one DBML file and run `dbml:sync`; see [Using with AI Coding Agents](#-using-with-ai-coding-agents).

It is **not** a tool for reverse-engineering an existing database into DBML, and it does not generate controllers, factories or seeders.

## **🚀 Motivation**

Tired of manually writing Laravel Eloquent models and migration files from your database diagrams? This package automates the process, letting you focus on building features rather than repetitive boilerplate code. Say goodbye to manual schema-to-code conversion and embrace a faster, more efficient Laravel development workflow.

![DBML to Laravel Model & Migration Generator](https://github.com/user-attachments/assets/d15995b7-b95f-4524-b81d-65007b4549f8)

## **✨ Features**

* **DBML to Eloquent Models:** Generates models with `$fillable`, `$casts`, a `$table` property when the table name is non-conventional, and relationship methods.
* **Relationships:** `belongsTo` on the referencing model and `hasMany` on the referenced model, built from DBML `ref` / `Ref:` definitions.
* **DBML to Migrations:** Column types, nullability, defaults, `unique`, primary keys, auto-increment, enums, single and composite indexes, and foreign keys (`onDelete` / `onUpdate` for `cascade`, `restrict`, `set null`).
* **Casts:** `boolean`, `array` (json/jsonb), `datetime`, `date`, `float` and `decimal:N`, derived from DBML column types.
* **Incremental Sync:** `dbml:sync` diffs the DBML file against a snapshot and generates create/alter/drop migrations, then patches models only inside `@dbml-sync` markers.
* **Customizable Stubs:** Publish and edit the model and migration stubs to match your coding style.

### **Current Limitations**

* Only many-to-one / one-to-many refs (`>` and `<`) are used. One-to-one (`-`) and many-to-many (`<>`) refs are ignored — no foreign key, `hasOne`, pivot table or `belongsToMany` is generated for them.
* `$hidden`, factories and seeders are not generated.
* Columns without `not null` in DBML are generated as `->nullable()`.

## **📦 Installation**

To get started with the DBML to Laravel Eloquent Generator, follow these simple steps:

1. **Require the package via Composer:**
```bash
composer require egyjs/dbml-to-laravel --dev
```
2. **Publish the customizable stubs (optional, but recommended):**  
```bash
php artisan vendor:publish --tag=dbml-to-laravel-stubs
```
   This command will publish the stub files to `stubs/dbml-to-laravel/`, allowing you to customize the generated code.

### **Requirements**

* Laravel 8.x+  
* PHP 8.2+  
* Node.js 18+ runtime (the DBML parser is pre-bundled, so you never need to run `npm install` in your project)

## **💡 Usage**

Once installed, you can generate your Laravel models and migrations from a DBML file using the `dbml:generate` Artisan command. (`generate:dbml` also works as a legacy alias.)

1. **Create your DBML schema file** (e.g., database/schema.dbml).  
   **Example database/schema.dbml:**
```dbml
   Table users {  
     id int [pk, increment]  
     name varchar  
     email varchar [unique]  
     password varchar  
     created_at datetime  
     updated_at datetime  
   }

   Table posts {  
     id int [pk, increment]  
     user_id int [ref: > users.id]  
     title varchar  
     content text  
     created_at datetime  
     updated_at datetime  
   }
```
   Declare each relationship once — either inline (`[ref: > users.id]`) or as a top-level `Ref:` line, not both.

2. **Run the Artisan command:**  
```bash
php artisan dbml:generate database/schema.dbml
```

   Replace `database/schema.dbml` with the actual path to your DBML file.

### **Expected Output**

After running the command, the package will generate:

* **Eloquent Models:** In your `app/Models` directory (e.g., `app/Models/User.php`).  
* **Migration Files:** In your `database/migrations` directory (e.g., `2023_01_01_000000_create_posts_table.php`).

**Example Generated `app/Models/Post.php`** (actual output for the schema above):

```php
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Post extends Model
{
    use HasFactory;

    // @dbml-sync:table-property

    // @enddbml-sync:table-property

    // @dbml-sync:fillable
    protected $fillable = [
        'user_id',
        'title',
        'content'
    ];
    // @enddbml-sync:fillable

    // @dbml-sync:casts
    protected $casts = [
        'created_at' => 'datetime',
        'updated_at' => 'datetime'
    ];
    // @enddbml-sync:casts

    // @dbml-sync:relations
    public function user()
    {
        return $this->belongsTo(User::class, 'user_id', 'id');
    }

    // @enddbml-sync:relations
}
```

`app/Models/User.php` gets the inverse `posts()` → `hasMany(Post::class, 'user_id', 'id')` relation.

**Example Generated `database/migrations/2026_01_01_000001_create_posts_table.php`:**

```php
Schema::create('posts', function (Blueprint $table) {
    $table->increments('id')->nullable();
    $table->integer('user_id')->nullable();
    $table->foreign('user_id')->references('id')->on('users');
    $table->string('title', 255)->nullable();
    $table->text('content')->nullable();
    $table->timestamp('created_at')->nullable();
    $table->timestamp('updated_at')->nullable();
});
```

Add `not null` to DBML columns that must be required (e.g. `title varchar [not null]`).

### **Incremental Sync**

After your initial `generate:dbml`, you can iterate on your DBML schema and generate only the changes:

```bash
php artisan dbml:sync database/schema.dbml
```

This command:

1. Reads the snapshot `.dbml-sync.json` that `dbml:generate` writes **next to your DBML file** (e.g. `database/.dbml-sync.json` — commit it)
2. Compares it against the current DBML file
3. Generates **alter migrations** for added/modified/dropped columns, indexes, and foreign keys
4. Generates **create migrations** for new tables and **drop migrations** for removed tables
5. Patches existing Eloquent models within `@dbml-sync` markers (fillable, casts, relations, table property)

Use `--force` to skip confirmation prompts:

```bash
php artisan dbml:sync database/schema.dbml --force
```

**Workflow example:**

```bash
# Initial generation — creates models, migrations, and snapshot
php artisan dbml:generate database/schema.dbml

# ... edit schema.dbml (add columns, new tables, etc.)

# Incremental sync — only the changes
php artisan dbml:sync database/schema.dbml
```

**Model markers.** Generated models include `@dbml-sync` markers that the patcher targets:

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
    public function posts() { return $this->hasMany(Post::class); }
    // @enddbml-sync:relations
}
```

Code outside these markers is never touched by `dbml:sync`.

## **🤖 Using with AI Coding Agents**

This package is built to be driven by AI coding agents (Claude Code, Cursor, Codex, Copilot, etc.): the agent edits **one declarative file** instead of hand-writing migrations, and the generator produces deterministic Laravel code.

* **[Laravel Boost](https://github.com/laravel/boost) integration:** the package ships Boost guidelines and an agent skill in `resources/boost/`. Run `php artisan boost:install` (or `boost:update`) after installing this package and your agent learns the workflow automatically.
* **Non-interactive by design:** pass `--force` so commands never wait on a prompt. Without `--force`, `dbml:sync` in a shell with no TTY exits with code `1` and tells you to re-run with `--force` instead of hanging or silently doing nothing.
* **Preview first:** `--dry-run` (both commands) lists the files that would be written without touching models, migrations or the snapshot.
* **Machine-readable output:** `--json` prints one JSON report: `{"success", "dry_run", "written": [paths], "skipped": [{target, reason}], "errors": [messages]}`.
* **Reliable exit codes:** `0` on success, `1` on any error (missing file, parse error, reserved table name, unconfirmed prompt) — partial failures are never reported as success.
* **Agent workflow:**
  1. Edit `database/schema.dbml`.
  2. Optionally preview: `php artisan dbml:sync database/schema.dbml --dry-run --json`.
  3. Run `php artisan dbml:sync database/schema.dbml --force --json` (first time: `php artisan dbml:generate database/schema.dbml --json`) and check the exit code / `errors`.
  4. Review the files listed in `written`, then `php artisan migrate`.
* **Rules for agents:** do not hand-write migrations for tables managed in DBML, never remove `@dbml-sync` markers, and put custom model code outside the marker blocks.
* **LLM index:** a concise [`llms.txt`](llms.txt) summarises the package for LLM tools.

## **⚙️ Customization**

You can modify the generated model and migration templates by editing the stub files located in `stubs/dbml-to-laravel/` after publishing them. This allows you to tailor the output to your specific project needs, including adding custom traits, interfaces, or modifying default property definitions.

### Updating the bundled DBML parser (contributors only)

The published package already includes a fully bundled parser, so consumers never have to install Node dependencies. If you change `bin/parse-dbml.js`, run the following before opening a PR:

```bash
npm install
npm run build-parser
```

Commit both the updated source file and the generated `bin/parse-dbml.runtime.cjs` artifact so downstream users continue to benefit from the prepackaged parser.

## **🤝 Contributing**

We welcome contributions! Please see our [CONTRIBUTING.md](/CONTRIBUTING.md) for details on how you can help improve this project. Whether it's bug fixes, new features, or documentation improvements, your input is valuable.

### Contributor Documentation

Detailed contributor guides live in the [`docs/`](docs/) directory:

| Document | Description |
|----------|-------------|
| [Getting Started](docs/getting-started.md) | Clone to first green test |
| [Architecture](docs/architecture.md) | 3-stage pipeline, component map, data flow |
| [Adding Features](docs/adding-features.md) | Recipe-style "how do I add X" guides |
| [Stubs](docs/stubs.md) | Stub placeholders, override paths, customization |
| [Parser Bundle](docs/parser-bundle.md) | Node parser, esbuild bundle, JSON payload |
| [Testing](docs/testing.md) | Pest patterns, fixtures, adding test cases |
| [Gotchas](docs/gotchas.md) | Compatibility matrix, silent edge cases, traps |

## **❓ Support**

For questions, bug reports, or feature requests, please open an issue on the [GitHub Issues page](https://github.com/egyjs/dbml-to-laravel/issues). We'll do our best to respond promptly.

---

🎉 _This package was featured by [Laravel News](https://laravel-news.com/dbml-to-laravel). If you find it useful, give it a ⭐ on GitHub and share it with your Laravel team!_

## **📄 License**

This project is open-sourced software licensed under the [MIT License](/LICENSE.md).
