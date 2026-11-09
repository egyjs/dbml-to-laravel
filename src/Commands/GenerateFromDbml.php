<?php

namespace Egyjs\DbmlToLaravel\Commands;

use Egyjs\DbmlToLaravel\Commands\Concerns\ReportsResults;
use Egyjs\DbmlToLaravel\Generation\ColumnDefinitionBuilder;
use Egyjs\DbmlToLaravel\Generation\ModelContentBuilder;
use Egyjs\DbmlToLaravel\Parsing\Dbml\IndexDefinition;
use Egyjs\DbmlToLaravel\Parsing\Dbml\JsonSnapshotStore;
use Egyjs\DbmlToLaravel\Parsing\Dbml\Schema;
use Egyjs\DbmlToLaravel\Parsing\Dbml\Table;
use Egyjs\DbmlToLaravel\Parsing\NodeDbmlParser;
use Illuminate\Console\Command;
use Illuminate\Support\Str;
use Throwable;

class GenerateFromDbml extends Command
{
    use ReportsResults;

    protected $signature = 'dbml:generate {file}
        {--force : Overwrite existing models and migrations}
        {--dry-run : Show which files would be written without writing anything}
        {--json : Print a machine-readable JSON report instead of console output}';

    protected $aliases = ['generate:dbml'];

    protected $description = 'Generate models and migrations from a DBML file';

    /**
     * @var array<string, EnumDefinition>
     */
    private array $enums = [];

    private Schema $schema;

    private ColumnDefinitionBuilder $columnBuilder;

    private ModelContentBuilder $modelBuilder;

    public const FORBIDDEN_MODEL_NAMES = [
        'Class', 'Trait', 'Interface', 'Namespace', 'Object', 'Resource', 'String',
        'Array', 'Float', 'Int', 'Bool', 'Boolean', 'Null', 'Void', 'Iterable',
        'Parent', 'Self', 'Static', 'Mixed',
    ];

    /**
     * Counter for migration sequence numbering
     */
    private int $migrationCounter = 0;

    public function handle(): int
    {
        $file = $this->argument('file');
        $this->startReport();

        if (! file_exists($file)) {
            return $this->failWith("File not found: $file");
        }

        try {
            $parser = new NodeDbmlParser;
            $schema = $parser->parse($file);
        } catch (Throwable $e) {
            return $this->failWith('Failed to parse DBML file: '.$e->getMessage());
        }

        $this->schema = $schema;
        // Retrieve enums from the schema for use in migrations
        $this->enums = $schema->getEnums();
        $this->columnBuilder = new ColumnDefinitionBuilder($this->enums);
        $this->modelBuilder = new ModelContentBuilder;
        $this->migrationCounter = 0; // Reset counter for each run
        $generatedModels = 0;
        $generatedMigrations = 0;

        foreach ($schema->getTables() as $table) {
            if ($this->generateModel($table)) {
                $generatedModels++;
            }
            if ($this->generateMigration($table)) {
                $generatedMigrations++;
            }
        }

        $prefix = $this->isDryRun() ? '[dry-run] Would generate' : 'Generated';
        $this->info("$prefix $generatedModels models and $generatedMigrations migrations.");

        // Write snapshot for future dbml:sync runs
        $payload = $parser->getLastPayload();
        if ($payload !== null && ! $this->isDryRun()) {
            (new JsonSnapshotStore)->write($file, $payload);
        }

        if ($this->report['errors'] !== []) {
            $this->error(count($this->report['errors']).' error(s); see messages above.');
        }

        return $this->finish();
    }

    protected function generateModel(Table $table): bool
    {
        $modelName = Str::studly(Str::singular($table->getName()));

        // Check if the model name is a reserved PHP keyword
        if ($this->isForbiddenModelName($modelName)) {
            $this->reportError("Model \"$modelName\" for table \"{$table->getName()}\" cannot be created because it is a reserved PHP keyword.");

            return false;
        }

        $filePath = app_path("Models/$modelName.php");

        if (! $this->option('force') && $this->modelExists($filePath, $modelName)) {
            return false;
        }

        // Generate the content for the model
        $content = $this->generateModelContent($table, $modelName);

        if (! $content) {
            return false;
        }

        $this->writeGeneratedFile($filePath, $content);
        $this->info($this->isDryRun() ? "[dry-run] Would create model $modelName." : "Model $modelName created.");

        return true;
    }

    protected function generateMigration(Table $table): bool
    {
        $migrationName = 'create_'.Str::snake($table->getName()).'_table';

        // Generate timestamp with incremental counter
        $baseDate = now()->format('Y_m_d');
        $sequence = str_pad($this->migrationCounter, 6, '0', STR_PAD_LEFT);
        $timestamp = $baseDate.'_'.$sequence;

        $fileName = $timestamp.'_'.$migrationName.'.php';
        $filePath = database_path("migrations/$fileName");

        // Check if migration already exists
        if (! $this->option('force') && $this->migrationExists($table->getName())) {
            $this->warn("Migration for table {$table->getName()} already exists. Skipping...");
            $this->reportSkipped("migration:{$table->getName()}", 'exists (use --force to overwrite)');

            return false;
        }

        $content = $this->generateMigrationContent($table);

        if (! $content) {
            return false;
        }

        $this->writeGeneratedFile($filePath, $content);
        $this->info($this->isDryRun() ? "[dry-run] Would create migration for {$table->getName()}." : "Migration for {$table->getName()} created.");

        // Increment counter for next migration
        $this->migrationCounter++;

        return true;
    }

    private function generateModelContent(Table $table, string $modelName): ?string
    {
        $stub = $this->getValidatedStubContent('model.stub', 'Model');
        if ($stub === null) {
            return null;
        }

        return $this->modelBuilder->generateModelContent($table, $modelName, $this->schema, $stub);
    }

    private function generateMigrationContent(Table $table): ?string
    {
        $columnDefinitions = $this->columnBuilder->generateMigrationColumns($table->getColumns());
        $indexDefinitions = $this->generateIndexDefinitions($table);

        $stub = $this->getValidatedStubContent('migration.stub', 'Migration');
        if ($stub === null) {
            return null;
        }

        return str_replace(
            ['{{ tableName }}', '{{ columns }}', '{{ indexes }}'],
            [$table->getName(), $columnDefinitions, $indexDefinitions],
            $stub
        );
    }

    private function getStubContent(string $stubName): ?string
    {
        // First check if stubs have been published to the Laravel app
        $publishedStubPath = base_path("stubs/dbml-to-laravel/$stubName");
        if (file_exists($publishedStubPath)) {
            return file_get_contents($publishedStubPath);
        }

        // Fall back to package stubs
        $packageStubPath = __DIR__."/../../stubs/$stubName";

        return file_exists($packageStubPath) ? file_get_contents($packageStubPath) : null;
    }

    private function getValidatedStubContent(string $stubName, string $type): ?string
    {
        $stub = $this->getStubContent($stubName);
        if ($stub === null) {
            $this->reportError("$type stub not found.");

            return null;
        }

        return $stub;
    }

    private function isForbiddenModelName(string $modelName): bool
    {
        return in_array($modelName, self::FORBIDDEN_MODEL_NAMES, true);
    }

    private function modelExists(string $filePath, string $modelName): bool
    {
        // Check if the model file already exists
        if (file_exists($filePath)) {
            $this->warn("Model $modelName already exists. Use --force to overwrite.");
            $this->reportSkipped("model:$modelName", 'exists (use --force to overwrite)');

            return true;
        }

        return false;
    }

    private function migrationExists(string $tableName): bool
    {
        $migrationPattern = '*_create_'.Str::snake($tableName).'_table.php';
        $migrationPath = database_path('migrations');

        if (! is_dir($migrationPath)) {
            return false;
        }

        $existingMigrations = glob($migrationPath.'/'.$migrationPattern);

        return ! empty($existingMigrations);
    }

    private function generateIndexDefinitions(Table $table): string
    {
        $definitions = collect($table->getIndexes())
            ->map(fn (IndexDefinition $index) => $this->columnBuilder->buildIndexDefinition($index))
            ->filter()
            ->implode("\n");

        return $definitions === '' ? '' : "\n".$definitions;
    }
}
