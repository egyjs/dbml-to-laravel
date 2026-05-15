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
            ->toContain("\$table->string('country_code', 255)")
            ->toContain("\$table->foreign('country_code')->references('code')->on('countries')")
            ->toContain("enum('role'");
    } finally {
        Carbon::setTestNow();
        $application->useAppPath($originalAppPath);
        $application->useDatabasePath($originalDatabasePath);
        $filesystem->deleteDirectory($baseTempPath);
    }
});

it('respects column type when generating foreign key constraints', function () {
    $filesystem = new Filesystem();
    $baseTempPath = base_path('tests/.tmp/'.uniqid('dbml_typed_fk_', true));
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
        $fixture = __DIR__.'/../Fixtures/unsigned-fk.dbml';

        artisan('generate:dbml', ['file' => $fixture, '--force' => true])
            ->assertExitCode(Command::SUCCESS);

        $migrationFiles = glob($databasePath.'/migrations/*.php');
        $ordersMigration = collect($migrationFiles)
            ->first(fn (string $file) => str_contains($file, 'create_orders_table'));

        expect($ordersMigration)->not->toBeNull();

        $contents = file_get_contents($ordersMigration);
        expect($contents)
            ->toContain("\$table->unsignedInteger('user_id')")
            ->toContain("\$table->foreign('user_id')->references('id')->on('users')")
            ->toContain("\$table->foreignId('product_id')->constrained('products')")
            ->toContain("\$table->string('coupon_code', 255)")
            ->toContain("\$table->foreign('coupon_code')->references('code')->on('coupons')")
            ->not->toContain("foreignId('user_id')");
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
