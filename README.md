# **DBML to Laravel Eloquent Generator ✨**

**Generate Laravel Eloquent models and migration files directly from your DBML (Database Markup Language) diagrams, accelerating Laravel development and streamlining your schema-to-code workflow.**

> 📰 **Featured on [Laravel News](https://laravel-news.com/dbml-to-laravel)** — the official community blog for Laravel developers!

[![Latest Version on Packagist](https://img.shields.io/packagist/v/egyjs/dbml-to-laravel.svg?style=flat-square)](https://packagist.org/packages/egyjs/dbml-to-laravel)
[![GitHub Tests Action Status](https://img.shields.io/github/actions/workflow/status/egyjs/dbml-to-laravel/run-tests.yml?branch=main&label=tests&style=flat-square)](https://github.com/egyjs/dbml-to-laravel/actions?query=workflow%3Arun-tests+branch%3Amain)
[![Total Downloads](https://img.shields.io/packagist/dt/egyjs/dbml-to-laravel.svg?style=flat-square)](https://packagist.org/packages/egyjs/dbml-to-laravel)
[![Featured on Laravel News](https://img.shields.io/badge/Laravel%20News-Featured-orange?style=flat-square)](https://laravel-news.com/dbml-to-laravel)

## **🚀 Motivation**

Tired of manually writing Laravel Eloquent models and migration files from your database diagrams? This package automates the process, letting you focus on building features rather than repetitive boilerplate code. Say goodbye to manual schema-to-code conversion and embrace a faster, more efficient Laravel development workflow.

## **✨ Features**

* **DBML to Laravel Models:** Automatically generate Eloquent models with fillable properties, hidden attributes, and defined relationships (one-to-one, one-to-many, many-to-many).  
* **DBML to Migration Files:** Generate clean and accurate migration files with proper data types, nullability, default values, and foreign key constraints, directly from your DBML schema.  
* **Customizable Stubs:** Easily modify the default model and migration stubs to align with your project's coding style and specific requirements.  
* **Relationship Parsing:** Intelligently parses DBML relationships to create correct Eloquent relationship methods (e.g., `hasMany`, `belongsTo`, `belongsToMany`).  
* **Casts Support:** Automatically adds common Eloquent casts (e.g., JSON to arrays, timestamps to datetime objects) based on DBML column types.
  
![DBML to Laravel Model & Migration Generator](https://github.com/user-attachments/assets/d0ab35a5-84ab-4060-b380-b16253cf842b)

## **📦 Installation**

To get started with the DBML to Laravel Eloquent Generator, follow these simple steps:

1. **Require the package via Composer:**
```bash
composer require egyjs/dbml-to-laravel --dev
```
3. **Publish the customizable stubs (optional, but recommended):**  
```bash
php artisan vendor:publish --tag=dbml-to-laravel-stubs
```
   This command will publish the stub files to `stubs/dbml-to-laravel/`, allowing you to customize the generated code.

### **Requirements**

* Laravel 8.x+
* PHP 8.0+
* Node.js 18+ (for the bundled `@dbml/core` parser; run `npm install` in your project to install the JavaScript dependency)

## **💡 Usage**

Once installed, you can generate your Laravel models and migrations from a DBML file using the `generate:dbml` Artisan command.

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

   Ref: posts.user_id > users.id
```
3. **Run the Artisan command:**  
```bash
php artisan generate:dbml database/schema.dbml
```

   Replace `database/schema.dbml` with the actual path to your DBML file.

### **Expected Output**

After running the command, the package will generate:

* **Eloquent Models:** In your `app/Models` directory (e.g., `app/Models/User.php`).  
* **Migration Files:** In your `database/migrations` directory (e.g., `2023_01_01_000000_create_posts_table.php`).

**Example Generated `app/Models/Post.php`:**

```php
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Post extends Model
{
    use HasFactory;

    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'user_id',
        'title',
        'content',
    ];

    /**
     * Get the user that owns the Post.
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
```

Example Generated `database/migrations/2023_01_01_000000_create_posts_table.php`:

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('posts', function (Blueprint $table) {
            $table->increments('id');
            $table->integer('user_id');
            $table->string('title');
            $table->text('content');
            $table->timestamps();

            $table->foreign('user_id')->references('id')->on('users')->onDelete('cascade');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('posts');
    }
}
```

## **⚙️ Customization**

You can modify the generated model and migration templates by editing the stub files located in `stubs/dbml-to-laravel/` after publishing them. This allows you to tailor the output to your specific project needs, including adding custom traits, interfaces, or modifying default property definitions.

## **🤝 Contributing**

We welcome contributions! Please see our [CONTRIBUTING.md](/CONTRIBUTING.md) for details on how you can help improve this project. Whether it's bug fixes, new features, or documentation improvements, your input is valuable.

## **❓ Support**

For questions, bug reports, or feature requests, please open an issue on the [GitHub Issues page](https://github.com/egyjs/dbml-to-laravel/issues). We'll do our best to respond promptly.

---

🎉 _This package was featured by [Laravel News](https://laravel-news.com/dbml-to-laravel). If you find it useful, give it a ⭐ on GitHub and share it with your Laravel team!_

## **📄 License**

This project is open-sourced software licensed under the [MIT License](/LICENSE.md).
