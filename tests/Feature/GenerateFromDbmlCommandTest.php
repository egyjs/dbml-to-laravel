<?php

declare(strict_types=1);

use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Filesystem\Filesystem;
use function Pest\Laravel\artisan;

it('generates models and migrations from DBML', function () {
    $filesystem = new Filesystem;
    $baseTempPath = base_path('tests/.tmp/'.uniqid('dbml_', true));
    $appPath = $baseTempPath.'/app';
    $databasePath = $baseTempPath.'/database';

    $filesystem->makeDirectory($appPath.'/Models', 0755, true, true);
    $filesystem->makeDirectory($databasePath.'/migrations', 0755, true, true);

    $application = app();
    $originalAppPath = $application->path();
    $originalDatabasePath = $application->databasePath();

    $application->useAppPath($appPath);
    $application->useDatabasePath($databasePath);

    Carbon::setTestNow(Carbon::create(2024, 1, 2, 10));

    try {
        $fixture = __DIR__.'/../Fixtures/simple.dbml';

        artisan('generate:dbml', ['file' => $fixture, '--force' => true])
            ->assertExitCode(Command::SUCCESS);

        expect(file_exists($appPath.'/Models/User.php'))->toBeTrue();

        $migrationFiles = glob($databasePath.'/migrations/*.php');
        expect($migrationFiles)->toHaveCount(2);

        $usersMigration = collect($migrationFiles)
            ->first(fn (string $file) => str_contains($file, 'create_users_table'));

        expect($usersMigration)->not->toBeNull();

        $migrationContents = file_get_contents($usersMigration);
        expect($migrationContents)
            ->toContain("foreignId('country_code')->constrained('countries', 'code')")
            ->toContain("enum('role'");
    } finally {
        Carbon::setTestNow();
        $application->useAppPath($originalAppPath);
        $application->useDatabasePath($originalDatabasePath);
        $filesystem->deleteDirectory($baseTempPath);
    }
});

it('fails when DBML file is missing', function () {
    $missing = base_path('tests/Fixtures/missing-file.dbml');

    artisan('generate:dbml', ['file' => $missing])
        ->expectsOutput("File not found: $missing")
        ->assertExitCode(Command::FAILURE);
});

it('uses matching unsigned integer FK column types', function () {
    $filesystem = new Filesystem;
    $baseTempPath = base_path('tests/.tmp/'.uniqid('dbml_', true));
    $databasePath = $baseTempPath.'/database';

    $filesystem->makeDirectory($baseTempPath.'/app/Models', 0755, true, true);
    $filesystem->makeDirectory($databasePath.'/migrations', 0755, true, true);

    $application = app();
    $originalAppPath = $application->path();
    $originalDatabasePath = $application->databasePath();

    $application->useAppPath($baseTempPath.'/app');
    $application->useDatabasePath($databasePath);

    Carbon::setTestNow(Carbon::create(2024, 1, 2, 10));

    try {
        $fixture = __DIR__.'/../Fixtures/unsigned-foreign-keys.dbml';

        artisan('generate:dbml', ['file' => $fixture, '--force' => true])
            ->assertExitCode(Command::SUCCESS);

        $migrationFiles = glob($databasePath.'/migrations/*.php');
        expect($migrationFiles)->toHaveCount(4);

        $pivotMigration = collect($migrationFiles)
            ->first(fn (string $file) => str_contains($file, 'create_users_sites_accounts_table'));

        expect($pivotMigration)->not->toBeNull();

        $migrationContents = file_get_contents($pivotMigration);
        expect($migrationContents)
            ->toContain("unsignedInteger('user_id')")
            ->toContain("foreign('user_id')->references('id')->on('users')->cascadeOnDelete()->cascadeOnUpdate()")
            ->toContain("unsignedSmallInteger('site_id')")
            ->toContain("foreign('site_id')->references('id')->on('sites')->cascadeOnDelete()->cascadeOnUpdate()")
            ->toContain("foreignId('account_id')->constrained('accounts')->cascadeOnDelete()->cascadeOnUpdate()");
    } finally {
        Carbon::setTestNow();
        $application->useAppPath($originalAppPath);
        $application->useDatabasePath($originalDatabasePath);
        $filesystem->deleteDirectory($baseTempPath);
    }
});
