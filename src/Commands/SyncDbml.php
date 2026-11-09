<?php

declare(strict_types=1);

namespace Egyjs\DbmlToLaravel\Commands;

use Egyjs\DbmlToLaravel\Commands\Concerns\ReportsResults;
use Egyjs\DbmlToLaravel\Generation\AlterMigrationGenerator;
use Egyjs\DbmlToLaravel\Generation\ColumnDefinitionBuilder;
use Egyjs\DbmlToLaravel\Generation\ModelContentBuilder;
use Egyjs\DbmlToLaravel\Generation\ModelPatcher;
use Egyjs\DbmlToLaravel\Parsing\Dbml\JsonSnapshotStore;
use Egyjs\DbmlToLaravel\Parsing\Dbml\Schema;
use Egyjs\DbmlToLaravel\Parsing\Dbml\SchemaDiff;
use Egyjs\DbmlToLaravel\Parsing\Dbml\SchemaDiffer;
use Egyjs\DbmlToLaravel\Parsing\Dbml\Table;
use Egyjs\DbmlToLaravel\Parsing\Dbml\TableDiff;
use Egyjs\DbmlToLaravel\Parsing\NodeDbmlParser;
use Illuminate\Console\Command;
use Illuminate\Support\Str;
use Throwable;

class SyncDbml extends Command
{
    use ReportsResults;

    protected $signature = 'dbml:sync {file}
        {--force : Apply all changes without confirmation prompts (required in non-interactive shells)}
        {--dry-run : Show the detected changes and files that would be written without writing anything}
        {--json : Print a machine-readable JSON report instead of console output}';

    protected $description = 'Generate incremental migrations and update models by syncing DBML changes';

    private int $migrationCounter = 0;

    public function handle(): int
    {
        $file = $this->argument('file');
        $this->startReport();

        if (! file_exists($file)) {
            return $this->failWith("File not found: $file");
        }

        $snapshotStore = new JsonSnapshotStore;

        if (! $snapshotStore->exists($file)) {
            return $this->failWith('No snapshot found. Run generate:dbml first to create a snapshot.');
        }

        try {
            $parser = new NodeDbmlParser;
            $newSchema = $parser->parse($file);
            $payload = $parser->getLastPayload();
        } catch (Throwable $e) {
            return $this->failWith('Failed to parse DBML file: '.$e->getMessage());
        }

        $oldSchema = $snapshotStore->read($file);

        if ($oldSchema === null) {
            return $this->failWith('Failed to read snapshot. Delete .dbml-sync.json and re-run generate:dbml.');
        }

        $diff = SchemaDiffer::diff($oldSchema, $newSchema);

        if ($diff->isEmpty()) {
            $this->info('No schema changes detected.');

            return $this->finish();
        }

        $this->displayDiffSummary($diff);

        $dryRun = $this->isDryRun();
        $force = (bool) $this->option('force');

        // Prompts cannot be answered without a TTY: confirm() would silently
        // return "no" and the run would look successful while doing nothing.
        if (! $dryRun && ! $force && ! $this->canPrompt() && $this->needsConfirmation($diff)) {
            return $this->failWith(
                'Confirmation required (dropped tables or model changes) but the shell is non-interactive. '
                .'Re-run with --force to apply, or --dry-run to preview.'
            );
        }

        if (! empty($diff->droppedTables) && ! $force && ! $dryRun) {
            if (! $this->confirm('Dropped tables detected. This will generate DROP TABLE migrations. Continue?')) {
                return $this->failWith('Sync cancelled: dropped tables were not confirmed. No files were written.');
            }
        }

        $enums = $newSchema->getEnums();
        $columnBuilder = new ColumnDefinitionBuilder($enums);
        $modelBuilder = new ModelContentBuilder;
        $alterGenerator = new AlterMigrationGenerator($columnBuilder);
        $modelPatcher = new ModelPatcher($modelBuilder);

        $generatedMigrations = 0;
        $patchedModels = 0;

        $this->migrationCounter = 0;

        foreach ($diff->addedTables as $table) {
            $modelName = Str::studly(Str::singular($table->getName()));

            if ($this->isForbiddenModelName($modelName)) {
                $this->reportError("Model \"$modelName\" for table \"{$table->getName()}\" cannot be created because it is a reserved PHP keyword.");

                continue;
            }

            $this->generateCreateMigration($table, $columnBuilder);
            $generatedMigrations++;
        }

        foreach ($diff->modifiedTables as $tableDiff) {
            if (! $tableDiff->isEmpty()) {
                $this->generateAlterMigration($tableDiff, $alterGenerator);
                $generatedMigrations++;
            }

            $modelName = Str::studly(Str::singular($tableDiff->tableName));
            $modelPath = app_path("Models/$modelName.php");

            if (file_exists($modelPath)) {
                $modelContent = file_get_contents($modelPath);
                $table = $this->findTable($newSchema, $tableDiff->tableName);

                if ($table !== null) {
                    $patched = $modelPatcher->patch($modelContent, $table, $modelName, $newSchema);

                    if (! $force && ! $dryRun) {
                        $this->info("Model changes for {$modelName}:");
                        $this->line('  - Updated fillable, casts, and relations within @dbml-sync markers');

                        if (! $this->confirm("Apply model changes to {$modelName}?")) {
                            $this->reportSkipped("model:$modelName", 'declined at prompt');

                            continue;
                        }
                    }

                    $this->writeGeneratedFile($modelPath, $patched);
                    $this->info($dryRun ? "[dry-run] Would patch model {$modelName}." : "Model {$modelName} patched.");
                    $patchedModels++;
                }
            }
        }

        foreach ($diff->droppedTables as $tableName) {
            $this->generateDropMigration($tableName);
            $generatedMigrations++;
        }

        if ($payload !== null && ! $dryRun) {
            $snapshotStore->write($file, $payload);
        }

        $this->info(($dryRun ? '[dry-run] Would sync' : 'Synced').": $generatedMigrations migrations generated, $patchedModels models patched.");

        return $this->finish();
    }

    /**
     * Whether applying this diff would prompt the user (dropped tables or an existing model to patch).
     */
    private function needsConfirmation(SchemaDiff $diff): bool
    {
        if (! empty($diff->droppedTables)) {
            return true;
        }

        foreach ($diff->modifiedTables as $tableDiff) {
            $modelName = Str::studly(Str::singular($tableDiff->tableName));

            if (file_exists(app_path("Models/$modelName.php"))) {
                return true;
            }
        }

        return false;
    }

    private function displayDiffSummary(SchemaDiff $diff): void
    {
        foreach ($diff->addedTables as $table) {
            $this->info("+ Table {$table->getName()} (new)");
        }

        foreach ($diff->droppedTables as $tableName) {
            $this->warn("- Table {$tableName} (dropped)");
        }

        foreach ($diff->modifiedTables as $tableDiff) {
            $changes = [];

            if (! empty($tableDiff->addedColumns)) {
                $names = array_map(fn ($col) => $col->getName(), $tableDiff->addedColumns);
                $changes[] = 'added columns: '.implode(', ', $names);
            }

            if (! empty($tableDiff->droppedColumns)) {
                $names = array_map(fn ($col) => $col->getName(), $tableDiff->droppedColumns);
                $changes[] = 'dropped columns: '.implode(', ', $names);
            }

            if (! empty($tableDiff->modifiedColumns)) {
                $names = array_map(fn ($pair) => $pair->new->getName(), $tableDiff->modifiedColumns);
                $changes[] = 'modified columns: '.implode(', ', $names);
            }

            if (! empty($tableDiff->addedIndexes)) {
                $changes[] = count($tableDiff->addedIndexes).' added index(es)';
            }

            if (! empty($tableDiff->droppedIndexes)) {
                $changes[] = count($tableDiff->droppedIndexes).' dropped index(es)';
            }

            if (! empty($tableDiff->addedForeignKeys)) {
                $changes[] = count($tableDiff->addedForeignKeys).' added FK(s)';
            }

            if (! empty($tableDiff->droppedForeignKeys)) {
                $changes[] = count($tableDiff->droppedForeignKeys).' dropped FK(s)';
            }

            $this->line("~ Table {$tableDiff->tableName}: ".implode('; ', $changes));
        }
    }

    private function generateCreateMigration(Table $table, ColumnDefinitionBuilder $columnBuilder): void
    {
        $baseDate = now()->format('Y_m_d');
        $sequence = str_pad((string) $this->migrationCounter, 6, '0', STR_PAD_LEFT);
        $timestamp = $baseDate.'_'.$sequence;
        $migrationName = 'create_'.Str::snake($table->getName()).'_table';
        $fileName = $timestamp.'_'.$migrationName.'.php';
        $filePath = database_path("migrations/$fileName");

        $columnDefinitions = collect($table->getColumns())
            ->map(fn ($column) => $columnBuilder->buildColumnDefinition($column))
            ->implode("\n");

        $indexDefinitions = collect($table->getIndexes())
            ->map(fn ($index) => $columnBuilder->buildIndexDefinition($index))
            ->filter()
            ->implode("\n");

        if ($indexDefinitions !== '') {
            $indexDefinitions = "\n".$indexDefinitions;
        }

        $stubPath = base_path('stubs/dbml-to-laravel/migration.stub');
        if (! file_exists($stubPath)) {
            $stubPath = __DIR__.'/../../stubs/migration.stub';
        }

        $stub = file_get_contents($stubPath);
        $content = str_replace(
            ['{{ tableName }}', '{{ columns }}', '{{ indexes }}'],
            [$table->getName(), $columnDefinitions, $indexDefinitions],
            $stub
        );

        $this->writeGeneratedFile($filePath, $content);
        $this->info($this->isDryRun()
            ? "[dry-run] Would create migration for new table {$table->getName()}."
            : "Migration for new table {$table->getName()} created.");
        $this->migrationCounter++;
    }

    private function generateAlterMigration(TableDiff $tableDiff, AlterMigrationGenerator $generator): void
    {
        $baseDate = now()->format('Y_m_d');
        $sequence = str_pad((string) $this->migrationCounter, 6, '0', STR_PAD_LEFT);
        $timestamp = $baseDate.'_'.$sequence;
        $migrationName = 'sync_'.$tableDiff->tableName;
        $fileName = $timestamp.'_'.$migrationName.'.php';
        $filePath = database_path("migrations/$fileName");

        $stubPath = base_path('stubs/dbml-to-laravel/alter-migration.stub');
        if (! file_exists($stubPath)) {
            $stubPath = __DIR__.'/../../stubs/alter-migration.stub';
        }

        $stubContent = file_get_contents($stubPath);
        $content = $generator->generate($tableDiff, $stubContent);

        $this->writeGeneratedFile($filePath, $content);
        $this->info($this->isDryRun()
            ? "[dry-run] Would create alter migration for {$tableDiff->tableName}."
            : "Alter migration for {$tableDiff->tableName} created.");
        $this->migrationCounter++;
    }

    private function generateDropMigration(string $tableName): void
    {
        $baseDate = now()->format('Y_m_d');
        $sequence = str_pad((string) $this->migrationCounter, 6, '0', STR_PAD_LEFT);
        $timestamp = $baseDate.'_'.$sequence;
        $migrationName = 'drop_'.Str::snake($tableName).'_table';
        $fileName = $timestamp.'_'.$migrationName.'.php';
        $filePath = database_path("migrations/$fileName");

        $content = <<<'PHP'
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::dropIfExists('{{ tableName }}');
    }

    public function down(): void
    {
        // Cannot recreate dropped table automatically
    }
};
PHP;

        $content = str_replace('{{ tableName }}', $tableName, $content);

        $this->writeGeneratedFile($filePath, $content);
        $this->warn($this->isDryRun()
            ? "[dry-run] Would create drop migration for {$tableName}."
            : "Drop migration for {$tableName} created.");
        $this->migrationCounter++;
    }

    private function findTable(Schema $schema, string $tableName): ?Table
    {
        foreach ($schema->getTables() as $table) {
            if (strtolower($table->getName()) === strtolower($tableName)) {
                return $table;
            }
        }

        return null;
    }

    private function isForbiddenModelName(string $modelName): bool
    {
        return in_array($modelName, GenerateFromDbml::FORBIDDEN_MODEL_NAMES, true);
    }
}
