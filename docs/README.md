Read this when you first open the repo and want to know what it does and where to look.

## Project summary

`egyjs/dbml-to-laravel` is a Laravel package that generates Eloquent models and database migrations from DBML files. It ships a single Artisan command -- `generate:dbml {file} {--force}` -- registered by `DbmlToLaravelServiceProvider`. Internally the package runs a two-stage pipeline: a Node.js parser reads the DBML file and emits normalized JSON, then PHP builds a schema model from that JSON and generates model and migration files from stubs. The package supports PHP 8.0 through 8.4 and Laravel 8 through 13.

## Documentation index

| Document | Description |
|---|---|
| [getting-started.md](getting-started.md) | Clone to first green test |
| [architecture.md](architecture.md) | 3-stage pipeline, component map, data flow |
| [adding-features.md](adding-features.md) | Recipe-style "how do I add X" guides |
| [stubs.md](stubs.md) | Stub placeholders, override paths, customization |
| [parser-bundle.md](parser-bundle.md) | Node parser, esbuild bundle, JSON payload |
| [testing.md](testing.md) | Pest patterns, fixtures, adding test cases |
| [gotchas.md](gotchas.md) | Compatibility matrix, silent edge cases, traps |

## Quick links

- Source: `src/` -- PHP package code (ServiceProvider, Command, Parsing, Schema model)
- Node parser: `bin/parse-dbml.js` (source), `bin/parse-dbml.runtime.cjs` (committed bundle)
- Stubs: `stubs/model.stub`, `stubs/migration.stub`
- Tests: `tests/Feature/GenerateFromDbmlCommandTest.php`, `tests/TestCase.php`
- Fixtures: `tests/Fixtures/*.dbml`

Start with [getting-started.md](getting-started.md) to set up your environment and run the test suite.