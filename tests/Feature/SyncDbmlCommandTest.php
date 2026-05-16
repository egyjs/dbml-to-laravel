<?php

declare(strict_types=1);

use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Filesystem\Filesystem;
use function Pest\Laravel\artisan;

it('fails when DBML file is missing', function () {
    $missing = base_path('tests/Fixtures/missing-sync-file.dbml');

    artisan('dbml:sync', ['file' => $missing])
        ->expectsOutput("File not found: $missing")
        ->assertExitCode(Command::FAILURE);
});

it('fails when no snapshot exists', function () {
    $filesystem = new Filesystem;
    $baseTempPath = base_path('tests/.tmp/'.uniqid('dbml_sync_', true));
    $appPath = $baseTempPath.'/app';
    $databasePath = $baseTempPath.'/database';
    $fixtureDir = $baseTempPath.'/fixtures';

    $filesystem->makeDirectory($appPath.'/Models', 0755, true, true);
    $filesystem->makeDirectory($databasePath.'/migrations', 0755, true, true);
    $filesystem->makeDirectory($fixtureDir, 0755, true, true);

    $application = app();
    $originalAppPath = $application->path();
    $originalDatabasePath = $application->databasePath();

    $application->useAppPath($appPath);
    $application->useDatabasePath($databasePath);

    try {
        // Copy fixture to isolated temp dir so no .dbml-sync.json exists
        $fixture = __DIR__.'/../Fixtures/simple.dbml';
        $tempFixture = $fixtureDir.'/simple.dbml';
        $filesystem->copy($fixture, $tempFixture);

        artisan('dbml:sync', ['file' => $tempFixture])
            ->expectsOutput('No snapshot found. Run generate:dbml first to create a snapshot.')
            ->assertExitCode(Command::FAILURE);
    } finally {
        $application->useAppPath($originalAppPath);
        $application->useDatabasePath($originalDatabasePath);
        $filesystem->deleteDirectory($baseTempPath);
    }
});

it('syncs schema changes and generates alter migrations', function () {
    $filesystem = new Filesystem;
    $baseTempPath = base_path('tests/.tmp/'.uniqid('dbml_sync_', true));
    $appPath = $baseTempPath.'/app';
    $databasePath = $baseTempPath.'/database';
    $fixtureDir = $baseTempPath.'/fixtures';

    $filesystem->makeDirectory($appPath.'/Models', 0755, true, true);
    $filesystem->makeDirectory($databasePath.'/migrations', 0755, true, true);
    $filesystem->makeDirectory($fixtureDir, 0755, true, true);

    $application = app();
    $originalAppPath = $application->path();
    $originalDatabasePath = $application->databasePath();

    $application->useAppPath($appPath);
    $application->useDatabasePath($databasePath);

    Carbon::setTestNow(Carbon::create(2024, 1, 2, 10));

    try {
        // Copy base fixture to temp dir so snapshot is written there
        $baseFixture = __DIR__.'/../Fixtures/sync-base.dbml';
        $tempBaseFixture = $fixtureDir.'/sync-base.dbml';
        $filesystem->copy($baseFixture, $tempBaseFixture);

        // Step 1: Run generate:dbml to create initial snapshot
        artisan('generate:dbml', ['file' => $tempBaseFixture, '--force' => true])
            ->assertExitCode(Command::SUCCESS);

        // Verify snapshot was created
        expect(file_exists($fixtureDir.'/.dbml-sync.json'))->toBeTrue();

        // Verify initial models and migrations were created
        expect(file_exists($appPath.'/Models/User.php'))->toBeTrue();
        expect(file_exists($appPath.'/Models/Post.php'))->toBeTrue();

        $initialMigrations = glob($databasePath.'/migrations/*.php');
        expect($initialMigrations)->toHaveCount(2);

        // Step 2: Replace base fixture with modified version
        $modifiedFixture = __DIR__.'/../Fixtures/sync-modified.dbml';
        $filesystem->copy($modifiedFixture, $tempBaseFixture);

        // Step 3: Run dbml:sync to detect changes
        artisan('dbml:sync', ['file' => $tempBaseFixture, '--force' => true])
            ->assertExitCode(Command::SUCCESS);

        // Verify alter migration was created
        $allMigrations = glob($databasePath.'/migrations/*.php');
        expect(count($allMigrations))->toBeGreaterThan(2);

        // Check that an alter migration for users table exists (bio column added)
        $usersAlterMigration = collect($allMigrations)
            ->first(fn (string $file) => str_contains($file, 'sync_users'));

        expect($usersAlterMigration)->not->toBeNull();

        $usersAlterContent = file_get_contents($usersAlterMigration);
        expect($usersAlterContent)->toContain("Schema::table('users'");

        // Check that an alter migration for posts table exists (body, published columns added)
        $postsAlterMigration = collect($allMigrations)
            ->first(fn (string $file) => str_contains($file, 'sync_posts'));

        expect($postsAlterMigration)->not->toBeNull();

        // Check that a create migration for comments table exists
        $commentsCreateMigration = collect($allMigrations)
            ->first(fn (string $file) => str_contains($file, 'create_comments_table'));

        expect($commentsCreateMigration)->not->toBeNull();

        // Check that the role enum table was dropped (user_role enum no longer referenced)
        // and user_role enum is gone from the modified schema
        // Actually, the enum is still there. The dropped table would be "none" in this case.
        // Let's check for drop migration - there shouldn't be one since no tables were dropped
        $dropMigrations = collect($allMigrations)
            ->filter(fn (string $file) => str_contains($file, 'drop_'));

        // No tables were dropped, so no drop migrations
        expect($dropMigrations)->toHaveCount(0);
    } finally {
        Carbon::setTestNow();
        $application->useAppPath($originalAppPath);
        $application->useDatabasePath($originalDatabasePath);
        $filesystem->deleteDirectory($baseTempPath);
    }
});

it('detects dropped tables and generates drop migration', function () {
    $filesystem = new Filesystem;
    $baseTempPath = base_path('tests/.tmp/'.uniqid('dbml_sync_', true));
    $appPath = $baseTempPath.'/app';
    $databasePath = $baseTempPath.'/database';
    $fixtureDir = $baseTempPath.'/fixtures';

    $filesystem->makeDirectory($appPath.'/Models', 0755, true, true);
    $filesystem->makeDirectory($databasePath.'/migrations', 0755, true, true);
    $filesystem->makeDirectory($fixtureDir, 0755, true, true);

    $application = app();
    $originalAppPath = $application->path();
    $originalDatabasePath = $application->databasePath();

    $application->useAppPath($appPath);
    $application->useDatabasePath($databasePath);

    Carbon::setTestNow(Carbon::create(2024, 1, 2, 10));

    try {
        // Use simple.dbml as base
        $baseFixture = __DIR__.'/../Fixtures/simple.dbml';
        $tempFixture = $fixtureDir.'/sync-test.dbml';
        $filesystem->copy($baseFixture, $tempFixture);

        // Generate initial snapshot
        artisan('generate:dbml', ['file' => $tempFixture, '--force' => true])
            ->assertExitCode(Command::SUCCESS);

        // Now create a DBML with only the users table (countries dropped)
        $reducedDbml = <<<'DBML'
Table users {
  id int [pk]
  name varchar
  role user_role
}

Enum user_role {
  admin
  editor
}
DBML;
        file_put_contents($tempFixture, $reducedDbml);

        // Run sync with --force to skip confirmation
        artisan('dbml:sync', ['file' => $tempFixture, '--force' => true])
            ->assertExitCode(Command::SUCCESS);

        $allMigrations = glob($databasePath.'/migrations/*.php');
        $dropMigration = collect($allMigrations)
            ->first(fn (string $file) => str_contains($file, 'drop_countries'));

        expect($dropMigration)->not->toBeNull();

        $dropContent = file_get_contents($dropMigration);
        expect($dropContent)->toContain("Schema::dropIfExists('countries')");
    } finally {
        Carbon::setTestNow();
        $application->useAppPath($originalAppPath);
        $application->useDatabasePath($originalDatabasePath);
        $filesystem->deleteDirectory($baseTempPath);
    }
});

it('reports no changes when schema is identical', function () {
    $filesystem = new Filesystem;
    $baseTempPath = base_path('tests/.tmp/'.uniqid('dbml_sync_', true));
    $appPath = $baseTempPath.'/app';
    $databasePath = $baseTempPath.'/database';
    $fixtureDir = $baseTempPath.'/fixtures';

    $filesystem->makeDirectory($appPath.'/Models', 0755, true, true);
    $filesystem->makeDirectory($databasePath.'/migrations', 0755, true, true);
    $filesystem->makeDirectory($fixtureDir, 0755, true, true);

    $application = app();
    $originalAppPath = $application->path();
    $originalDatabasePath = $application->databasePath();

    $application->useAppPath($appPath);
    $application->useDatabasePath($databasePath);

    try {
        $baseFixture = __DIR__.'/../Fixtures/simple.dbml';
        $tempFixture = $fixtureDir.'/sync-identical.dbml';
        $filesystem->copy($baseFixture, $tempFixture);

        // Generate initial snapshot
        artisan('generate:dbml', ['file' => $tempFixture, '--force' => true])
            ->assertExitCode(Command::SUCCESS);

        // Run sync on the same file - no changes
        artisan('dbml:sync', ['file' => $tempFixture])
            ->expectsOutput('No schema changes detected.')
            ->assertExitCode(Command::SUCCESS);
    } finally {
        $application->useAppPath($originalAppPath);
        $application->useDatabasePath($originalDatabasePath);
        $filesystem->deleteDirectory($baseTempPath);
    }
});

it('patches existing models with sync markers', function () {
    $filesystem = new Filesystem;
    $baseTempPath = base_path('tests/.tmp/'.uniqid('dbml_sync_', true));
    $appPath = $baseTempPath.'/app';
    $databasePath = $baseTempPath.'/database';
    $fixtureDir = $baseTempPath.'/fixtures';

    $filesystem->makeDirectory($appPath.'/Models', 0755, true, true);
    $filesystem->makeDirectory($databasePath.'/migrations', 0755, true, true);
    $filesystem->makeDirectory($fixtureDir, 0755, true, true);

    $application = app();
    $originalAppPath = $application->path();
    $originalDatabasePath = $application->databasePath();

    $application->useAppPath($appPath);
    $application->useDatabasePath($databasePath);

    Carbon::setTestNow(Carbon::create(2024, 1, 2, 10));

    try {
        $baseFixture = __DIR__.'/../Fixtures/sync-base.dbml';
        $tempFixture = $fixtureDir.'/sync-patch.dbml';
        $filesystem->copy($baseFixture, $tempFixture);

        // Generate initial model + snapshot
        artisan('generate:dbml', ['file' => $tempFixture, '--force' => true])
            ->assertExitCode(Command::SUCCESS);

        // Verify model was created with markers
        $userModel = file_get_contents($appPath.'/Models/User.php');
        expect($userModel)->toContain('@dbml-sync:fillable');
        expect($userModel)->toContain('@enddbml-sync:fillable');

        // Now modify the DBML
        $modifiedFixture = __DIR__.'/../Fixtures/sync-modified.dbml';
        $filesystem->copy($modifiedFixture, $tempFixture);

        // Run sync with --force
        artisan('dbml:sync', ['file' => $tempFixture, '--force' => true])
            ->assertExitCode(Command::SUCCESS);

        // Verify model was patched
        $patchedModel = file_get_contents($appPath.'/Models/User.php');
        expect($patchedModel)->toContain('bio');
    } finally {
        Carbon::setTestNow();
        $application->useAppPath($originalAppPath);
        $application->useDatabasePath($originalDatabasePath);
        $filesystem->deleteDirectory($baseTempPath);
    }
});