# DBML to Laravel Model & Migration Generator

Generate Laravel Eloquent models and migration files directly from your DBML (Database Markup Language) files with ease. Learn more about DBML at [dbdiagram.io](https://dbdiagram.io/). This package helps Laravel developers streamline the process of turning DBML diagrams into fully functional code. Say goodbye to manually writing repetitive code and let this command-line utility do the work for you.

## Features

- **DBML to Laravel Models**: Automatically generate Laravel Eloquent models from DBML files, including fillable properties and relationships.
- **DBML to Migration Files**: Generate migration files directly from your DBML tables with proper data types and relationships.
- **Enum Support**: Handle `enum` fields with ease, automatically integrating them into both models and migrations.
- **Relationship Parsing**: Automatically generate `belongsTo`, `hasOne`, and `hasMany` relationships based on DBML references.
- **Customizable Stubs**: Modify generated model and migration templates to fit your coding style.

## Installation

To use this package, add it to your Laravel project via Composer:

```bash
composer require egyjs/dbml-to-laravel
```

Then, publish the stubs:

```bash
php artisan vendor:publish --tag=dbml-to-laravel-stubs
```

## Usage

This package provides a command that can generate models and migration files from a given DBML file:

```bash
php artisan generate:dbml path/to/your-schema.dbml
```

### Command Overview

- `file`: The path to your DBML file that contains the database schema.

## How It Works

The command parses your DBML file to read table definitions, columns, enums, and references. It then generates the following:

1. **Models**: Laravel Eloquent models are created based on the table definitions. Fields are marked as fillable where appropriate, and relations are generated.
2. **Migrations**: Laravel migration files are generated, including the proper data types for each field. Foreign key relationships are set up automatically.

### Generated Models

- **Fillable Fields**: All non-primary key columns are marked as fillable by default, allowing for mass assignment.
- **Casts**: Columns are automatically cast to appropriate data types for easier handling in your application. For example:
  - `json` fields are cast to arrays.
  - `timestamp` and `datetime` fields are cast to `datetime` objects, making date manipulation simpler.
- **Relationships**: Relationships (`belongsTo`, `hasOne`, `hasMany`) are generated based on foreign key references found in the DBML file.

## Customization

This package includes customizable stubs for both models and migrations. You can modify these stubs to fit your coding style by publishing them:

```bash
php artisan vendor:publish --tag=dbml-to-laravel-stubs
```

After publishing, the stubs will be located in `stubs/dbml-to-laravel/`. You can edit them to customize how the models and migrations are generated.

## Example

Suppose you have the following DBML file (`schema.dbml`):

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

Running the command:

```bash
php artisan generate:dbml schema.dbml
```

Will generate:

- **User Model** (`app/Models/User.php`) with relationships to posts.
- **Post Model** (`app/Models/Post.php`) with a `belongsTo` relationship to users.
- **Migration Files** for `users` and `posts` tables in the `database/migrations` folder.

## Requirements

- Laravel 8.x or higher.
- PHP 8.0 or higher.

## Documentation

For detailed usage, examples, and advanced configuration, visit our [documentation page](https://github.com/egyjs/dbml-to-laravel/wiki).

## Contributing

Feel free to open issues or submit pull requests. Any improvements or additional features are welcome.

If you're interested in contributing, check out our [contribution guidelines](https://github.com/egyjs/dbml-to-laravel/blob/main/CONTRIBUTING.md).

## License

This package is open-sourced software licensed under the [MIT license](LICENSE).

## SEO Keywords

- DBML to Laravel
- Laravel Model Generator
- Laravel Migration Generator
- DBML Parsing in Laravel
- Laravel Eloquent Relationships Generator
- Laravel Enum Handling
- Laravel Schema Automation

## Tags

`laravel`, `dbml`, `model generator`, `migration generator`, `eloquent`, `relationships`, `php`, `developer tools`, `schema generator`, `automation`

