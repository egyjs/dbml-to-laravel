Read this when you just cloned the repo and need to get tests passing before making changes.

## Prerequisites

- **PHP 8.0 -- 8.4**
- **Node.js 18+** (needed at runtime -- PHP shells out to `node` via `symfony/process`)
- **Composer 2.x**

## Clone and install

```bash
git clone https://github.com/egyjs/dbml-to-laravel.git
cd dbml-to-laravel
composer install
```

`composer install` auto-runs `testbench package:discover` via the `post-autoload-dump` hook. If it fails, run the command manually:

```bash
vendor/bin/testbench package:discover
```

## Node dependencies

Consumers never need `npm install` -- `bin/parse-dbml.runtime.cjs` is the committed esbuild bundle that ships with the package. Only contributors who are editing `bin/parse-dbml.js` need to install Node dev dependencies and rebuild:

```bash
npm install && npm run build-parser
```

After rebuilding, commit both `bin/parse-dbml.js` and `bin/parse-dbml.runtime.cjs`. See [parser-bundle.md](parser-bundle.md) for details on the bundling pipeline.

## Run tests

```bash
composer test                       # full Pest suite
vendor/bin/pest --filter="pattern"  # single test by name pattern
composer test-coverage              # with coverage report
```

The feature test invokes the Artisan command end-to-end and asserts generated file contents. See [testing.md](testing.md) for fixture conventions and adding new test cases.

## Code quality

```bash
composer format    # Laravel Pint (PSR-12)
composer analyse   # PHPStan via larastan
```

Run both before opening a PR. The project uses default larastan configuration (no committed `phpstan.neon`).

## Common setup failures

| Symptom | Fix |
|---|---|
| `testbench package:discover` fails during `composer install` | Run `vendor/bin/testbench package:discover` manually. Check that the `post-autoload-dump` hook in `composer.json` is intact. |
| `node` not found at runtime | Ensure Node.js 18+ is installed and on PATH. The PHP process calls `node` directly via `symfony/process`. |
| `bin/parse-dbml.runtime.cjs` missing | Reinstall the package, or in dev run `npm install && npm run build-parser` to rebuild the bundle. |
| Pest version conflict | The `composer.json` allows multiple Pest versions. If you see version errors, run `composer update` to resolve. |

## First contribution workflow

1. **Create a branch** using the convention `feature/short-description` or `fix/short-description`.
2. **Make your change** and add a test when applicable. See [testing.md](testing.md) for fixture and assertion patterns.
3. **Run the full quality gate** before pushing:
   ```bash
   composer format && composer analyse && composer test
   ```
4. **Push** and open a PR targeting `main`. Describe what changed and why; reference any related issues.

For guidance on where to put your change, see [architecture.md](architecture.md). For common pitfalls around compatibility and silent edge cases, see [gotchas.md](gotchas.md).