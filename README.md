# DBML to Laravel Model & Migration Generator

[![Latest Version on Packagist](https://img.shields.io/packagist/v/egyjs/dbml-to-laravel.svg?style=flat-square&get)](https://packagist.org/packages/egyjs/dbml-to-laravel)
[![GitHub Tests Action Status](https://img.shields.io/github/actions/workflow/status/egyjs/dbml-to-laravel/run-tests.yml?branch=main\&label=tests\&style=flat-square)](https://github.com/egyjs/dbml-to-laravel/actions?query=workflow%3Arun-tests+branch%3Amain)
[![Total Downloads](https://img.shields.io/packagist/dt/egyjs/dbml-to-laravel.svg?style=flat-square)](https://packagist.org/packages/egyjs/dbml-to-laravel)

![DBML to Laravel Model & Migration Generator](https://github.com/user-attachments/assets/d0ab35a5-84ab-4060-b380-b16253cf842b)

---

Generate Laravel Eloquent models and migration files directly from your DBML (Database Markup Language) files. Learn more about DBML at [dbdiagram.io](https://dbdiagram.io/). This package helps Laravel developers turn DBML diagrams into functional code fast.

## Features

* Generate Eloquent models from DBML
* Generate migration files from DBML
* Support for enum fields
* Auto-generate relationships: belongsTo, hasOne, hasMany
* Fully customizable stubs for models and migrations

## Installation

```bash
composer require egyjs/dbml-to-laravel --dev
php artisan vendor:publish --tag=dbml-to-laravel-stubs
```

## Usage

```bash
php artisan generate:dbml path/to/your-schema.dbml
```

### Command Argument

* `file`: Path to your DBML file

## How It Works

The package parses the DBML file and generates:

1. **Models**

   * `fillable` fields for mass assignment
   * `casts` for json, datetime, etc.
   * `relationships` based on references

2. **Migrations**

   * Fields with correct data types
   * Foreign keys with constraints

## Example

DBML:

```dbml
Table users {
  id int [primary key]
  name varchar
  email varchar [unique]
  created_at timestamp
  updated_at timestamp
}

Table posts {
  id int [primary key]
  title varchar
  content text
  user_id int [ref: > users.id]
  created_at timestamp
  updated_at timestamp
}
```

Command:

```bash
php artisan generate:dbml schema.dbml
```

Generates:

* `User` model with `hasMany(Post)`
* `Post` model with `belongsTo(User)`
* Migrations for both tables

## Customization

Edit published stubs in:

```
stubs/dbml-to-laravel/
```

## Requirements

* Laravel 8.x or higher

## Documentation

See full [documentation](https://github.com/egyjs/dbml-to-laravel/wiki) and [CONTRIBUTING.md](https://github.com/egyjs/dbml-to-laravel/blob/main/CONTRIBUTING.md).

## Contributing

Open issues or pull requests. Contributions are welcome. See [contribution guidelines](https://github.com/egyjs/dbml-to-laravel/blob/main/CONTRIBUTING.md).

## License

MIT License

## SEO Keywords

DBML to Laravel, Laravel Model Generator, Laravel Migration Generator, DBML Parsing in Laravel, Laravel Eloquent Relationships Generator, Laravel Enum Handling, Laravel Schema Automation

## Tags

`laravel`, `dbml`, `model generator`, `migration generator`, `eloquent`, `relationships`, `php`, `developer tools`, `schema generator`, `automation`
