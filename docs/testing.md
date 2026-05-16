Read this when you need to write or run tests for this package.

## Test Framework

Pest (compatible with v1, v2, v3 via multi-version composer constraint). Testbench (Orchestra) provides the Laravel app harness.

## Running Tests

```bash
composer test                              # full suite
vendor/bin/pest --filter="pattern"         # single test or pattern
vendor/bin/pest tests/Feature/GenerateFromDbmlCommandTest.php  # specific file
composer test-coverage                     # with coverage report
```

CI runs `vendor/bin/pest --ci` — see `.github/workflows/run-tests.yml`.

## Test Harness

`tests/TestCase.php` extends `Orchestra\Testbench\TestCase`:

- Registers `DbmlToLaravelServiceProvider` via `getPackageProviders()`
- Sets `database.default` to `testing` (SQLite in-memory)
- Configures model factory naming convention

`tests/Pest.php` binds `TestCase` as the base for all tests:

```php
uses(TestCase::class)->in(__DIR__);
```

## Fixture Pattern

DBML fixtures live in `tests/Fixtures/`:

| Fixture | Purpose |
|---------|---------|
| `simple.dbml` | Basic tables, enum, varchar FK |
| `unsigned-fk.dbml` | `int unsigned` / `bigint unsigned` foreign keys |
| `new-syntax.dbml` | Newer DBML syntax features |

Tests reference fixtures with `__DIR__.'/../Fixtures/<name>'`.

## How Tests Work

The feature test (`tests/Feature/GenerateFromDbmlCommandTest.php`) uses a temp-directory pattern:

1. Create a temp `app/Models` and `database/migrations` under `base_path('tests/.tmp/')`
2. Override Laravel's `appPath()` and `databasePath()` to point at temp dirs
3. Freeze time with `Carbon::setTestNow()` so migration timestamps are deterministic
4. Run `artisan('generate:dbml', ['file' => $fixture, '--force' => true])`
5. Assert generated files exist and contain expected content
6. Clean up: restore original paths, delete temp directory

Example:

```php
$filesystem = new Filesystem;
$baseTempPath = base_path('tests/.tmp/'.uniqid('dbml_', true));
$appPath = $baseTempPath.'/app';
$databasePath = $baseTempPath.'/database';

$filesystem->makeDirectory($appPath.'/Models', 0755, true, true);
$filesystem->makeDirectory($databasePath.'/migrations', 0755, true, true);

$application->useAppPath($appPath);
$application->useDatabasePath($databasePath);

Carbon::setTestNow(Carbon::create(2024, 1, 2, 10));

try {
    artisan('generate:dbml', ['file' => $fixture, '--force' => true])
        ->assertExitCode(Command::SUCCESS);

    expect(file_exists($appPath.'/Models/User.php'))->toBeTrue();
    // ... more assertions
} finally {
    Carbon::setTestNow();
    $application->useAppPath($originalAppPath);
    $application->useDatabasePath($originalDatabasePath);
    $filesystem->deleteDirectory($baseTempPath);
}
```

## Adding a New Test Case

1. Create a DBML fixture in `tests/Fixtures/` covering the feature/fix
2. Add a test in `tests/Feature/GenerateFromDbmlCommandTest.php` following the temp-directory pattern
3. Use `expect($migrationContents)->toContain(...)` to verify generated output
4. Use `->not->toContain(...)` to verify absence of unwanted output
5. Always use `--force` flag to avoid "file exists" skips
6. Always clean up in `finally` block

## CI Matrix

GitHub Actions (`.github/workflows/run-tests.yml`) tests:

- **OS**: ubuntu-latest, windows-latest
- **PHP**: 8.2, 8.3, 8.4
- **Laravel**: 11.\*, 12.\*
- **Stability**: prefer-lowest, prefer-stable
- Fail-fast enabled

PRs must pass on all matrix combinations.