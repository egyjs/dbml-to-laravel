<?php

declare(strict_types=1);

use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Facades\Artisan;

use function Pest\Laravel\artisan;

final class AgentSandbox
{
    public static ?self $current = null;

    public Filesystem $fs;

    public string $base;

    public string $appPath;

    public string $dbPath;

    public string $fixtureDir;

    public string $schema;

    public string $snapshot;

    private string $originalAppPath;

    private string $originalDbPath;

    public function __construct()
    {
        $this->fs = new Filesystem;
        $this->base = base_path('tests/.tmp/'.uniqid('dbml_agent_', true));
        $this->appPath = $this->base.'/app';
        $this->dbPath = $this->base.'/database';
        $this->fixtureDir = $this->base.'/fixtures';
        $this->schema = $this->fixtureDir.'/schema.dbml';
        $this->snapshot = $this->fixtureDir.'/.dbml-sync.json';

        $this->fs->makeDirectory($this->appPath.'/Models', 0755, true, true);
        $this->fs->makeDirectory($this->dbPath.'/migrations', 0755, true, true);
        $this->fs->makeDirectory($this->fixtureDir, 0755, true, true);

        $this->originalAppPath = app()->path();
        $this->originalDbPath = app()->databasePath();
        app()->useAppPath($this->appPath);
        app()->useDatabasePath($this->dbPath);

        Carbon::setTestNow(Carbon::create(2024, 1, 2, 10));
    }

    public function useFixture(string $name): void
    {
        $this->fs->copy(__DIR__.'/../Fixtures/'.$name, $this->schema);
    }

    /**
     * @return list<string>
     */
    public function migrations(): array
    {
        return glob($this->dbPath.'/migrations/*.php') ?: [];
    }

    public function relative(string $path): string
    {
        return 'tests/.tmp/'.basename($this->base).'/'.$path;
    }

    public function cleanup(): void
    {
        Carbon::setTestNow();
        app()->useAppPath($this->originalAppPath);
        app()->useDatabasePath($this->originalDbPath);
        $this->fs->deleteDirectory($this->base);
    }
}

function sandbox(): AgentSandbox
{
    return AgentSandbox::$current ??= new AgentSandbox;
}

afterEach(function () {
    AgentSandbox::$current?->cleanup();
    AgentSandbox::$current = null;
});

/**
 * @return array{0: int, 1: array<string, mixed>}
 */
function callForJson(string $command, array $parameters): array
{
    $exitCode = Artisan::call($command, $parameters + ['--json' => true]);
    $report = json_decode(Artisan::output(), true);

    expect($report)->toBeArray();

    return [$exitCode, $report];
}

it('generate --dry-run writes no models, migrations or snapshot', function () {
    sandbox()->useFixture('sync-base.dbml');

    artisan('dbml:generate', ['file' => sandbox()->schema, '--dry-run' => true])
        ->expectsOutput('[dry-run] Would create model User.')
        ->assertExitCode(Command::SUCCESS);

    expect(glob(sandbox()->appPath.'/Models/*.php'))->toBe([])
        ->and(sandbox()->migrations())->toBe([])
        ->and(file_exists(sandbox()->snapshot))->toBeFalse();
});

it('generate --json prints a parseable report of written files', function () {
    sandbox()->useFixture('sync-base.dbml');

    [$exitCode, $report] = callForJson('dbml:generate', ['file' => sandbox()->schema]);

    expect($exitCode)->toBe(Command::SUCCESS)
        ->and($report['success'])->toBeTrue()
        ->and($report['dry_run'])->toBeFalse()
        ->and($report['errors'])->toBe([])
        ->and($report['written'])->toHaveCount(4)
        ->and($report['written'])->toContain(sandbox()->relative('app/Models/User.php'));

    // Re-running without --force reports skips instead of silently doing nothing.
    [, $second] = callForJson('dbml:generate', ['file' => sandbox()->schema]);

    expect($second['written'])->toBe([])
        ->and(array_column($second['skipped'], 'target'))->toContain('model:User', 'migration:users');
});

it('generate exits with failure when a table cannot be generated', function () {
    file_put_contents(sandbox()->schema, "Table classes {\n  id int [pk, increment]\n}\n\nTable users {\n  id int [pk, increment]\n}\n");

    [$exitCode, $report] = callForJson('dbml:generate', ['file' => sandbox()->schema]);

    expect($exitCode)->toBe(Command::FAILURE)
        ->and($report['success'])->toBeFalse()
        ->and($report['errors'][0])->toContain('reserved PHP keyword')
        // Valid tables are still generated.
        ->and($report['written'])->toContain(sandbox()->relative('app/Models/User.php'));
});

it('generate --json reports a missing file as an error', function () {
    [$exitCode, $report] = callForJson('dbml:generate', ['file' => sandbox()->fixtureDir.'/missing.dbml']);

    expect($exitCode)->toBe(Command::FAILURE)
        ->and($report['errors'][0])->toStartWith('File not found:');
});

it('sync fails instead of silently skipping when a prompt cannot be answered', function () {
    sandbox()->useFixture('sync-base.dbml');
    expect(Artisan::call('dbml:generate', ['file' => sandbox()->schema, '--force' => true]))->toBe(Command::SUCCESS);

    $snapshotBefore = file_get_contents(sandbox()->snapshot);
    $migrationsBefore = sandbox()->migrations();

    sandbox()->useFixture('sync-modified.dbml');

    artisan('dbml:sync', ['file' => sandbox()->schema, '--no-interaction' => true])
        ->expectsOutputToContain('Re-run with --force')
        ->assertExitCode(Command::FAILURE);

    expect(sandbox()->migrations())->toBe($migrationsBefore)
        ->and(file_get_contents(sandbox()->snapshot))->toBe($snapshotBefore);
});

it('sync --dry-run previews changes without writing files or the snapshot', function () {
    sandbox()->useFixture('sync-base.dbml');
    expect(Artisan::call('dbml:generate', ['file' => sandbox()->schema, '--force' => true]))->toBe(Command::SUCCESS);

    $snapshotBefore = file_get_contents(sandbox()->snapshot);
    $migrationsBefore = sandbox()->migrations();
    $userModelBefore = file_get_contents(sandbox()->appPath.'/Models/User.php');

    sandbox()->useFixture('sync-modified.dbml');

    [$exitCode, $report] = callForJson('dbml:sync', ['file' => sandbox()->schema, '--dry-run' => true, '--no-interaction' => true]);

    $written = implode("\n", $report['written']);

    expect($exitCode)->toBe(Command::SUCCESS)
        ->and($report['dry_run'])->toBeTrue()
        ->and($written)->toContain('_sync_users.php')
        ->and($written)->toContain('_create_comments_table.php')
        ->and($written)->toContain('app/Models/User.php')
        ->and(sandbox()->migrations())->toBe($migrationsBefore)
        ->and(file_get_contents(sandbox()->snapshot))->toBe($snapshotBefore)
        ->and(file_get_contents(sandbox()->appPath.'/Models/User.php'))->toBe($userModelBefore);
});

it('sync --force --json applies changes and reports them', function () {
    sandbox()->useFixture('sync-base.dbml');
    expect(Artisan::call('dbml:generate', ['file' => sandbox()->schema, '--force' => true]))->toBe(Command::SUCCESS);
    sandbox()->useFixture('sync-modified.dbml');

    [$exitCode, $report] = callForJson('dbml:sync', ['file' => sandbox()->schema, '--force' => true, '--no-interaction' => true]);

    expect($exitCode)->toBe(Command::SUCCESS)
        ->and($report['success'])->toBeTrue()
        ->and($report['written'])->not->toBe([]);

    foreach ($report['written'] as $path) {
        expect(file_exists(base_path($path)))->toBeTrue();
    }
});

it('sync returns failure when the user declines dropping tables', function () {
    sandbox()->useFixture('sync-modified.dbml');
    expect(Artisan::call('dbml:generate', ['file' => sandbox()->schema, '--force' => true]))->toBe(Command::SUCCESS);

    $snapshotBefore = file_get_contents(sandbox()->snapshot);

    // Going back to the base schema drops the comments table.
    sandbox()->useFixture('sync-base.dbml');

    artisan('dbml:sync', ['file' => sandbox()->schema])
        ->expectsConfirmation('Dropped tables detected. This will generate DROP TABLE migrations. Continue?', 'no')
        ->expectsOutputToContain('Sync cancelled')
        ->assertExitCode(Command::FAILURE);

    expect(file_get_contents(sandbox()->snapshot))->toBe($snapshotBefore);
});
