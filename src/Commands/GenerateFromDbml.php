<?php

namespace Egyjs\DbmlToLaravel\Commands;

use Butschster\Dbml\Ast\Table\ColumnNode;
use Butschster\Dbml\Ast\TableNode;
use Illuminate\Console\Command;
use Illuminate\Filesystem\Filesystem;
use Butschster\Dbml\DbmlParserFactory;
use Illuminate\Support\Str;

class GenerateFromDbml extends Command
{
    protected $signature = 'generate:dbml {file} {--force : Overwrite existing files}';
    protected $description = 'Generate models and migrations from a DBML file';
    /**
     * @var \Butschster\Dbml\Ast\EnumNode[]
     */
    private array $enums;

    private const FORBIDDEN_MODEL_NAMES = [
        'Class', 'Trait', 'Interface', 'Namespace', 'Object', 'Resource', 'String',
        'Array', 'Float', 'Int', 'Bool', 'Boolean', 'Null', 'Void', 'Iterable',
        'Parent', 'Self', 'Static', 'Mixed'
    ];

    /**
     * Counter for migration sequence numbering
     */
    private int $migrationCounter = 0;

    public function handle(): int
    {
        $file = $this->argument('file');

        // Check if the provided file exists
        if (!file_exists($file)) {
            $this->error("File not found: $file");
            return Command::FAILURE;
        }

        try {
            $parser = DbmlParserFactory::create();
            $schema = $parser->parse(file_get_contents($file));
        } catch (\Exception $e) {
            $this->error("Failed to parse DBML file: " . $e->getMessage());
            return Command::FAILURE;
        }

        // Retrieve enums from the schema for use in migrations
        $this->enums = $schema->getEnums();
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

        $this->info("Generated $generatedModels models and $generatedMigrations migrations successfully.");
        return Command::SUCCESS;
    }

    protected function generateModel(TableNode $table): bool
    {
        $modelName = Str::studly(Str::singular($table->getName()));

        // Check if the model name is a reserved PHP keyword
        if ($this->isForbiddenModelName($modelName)) {
            $this->error("Model \"$modelName\" for table \"{$table->getName()}\" cannot be created because it is a reserved PHP keyword.");
            return false;
        }

        $filePath = app_path("Models/$modelName.php");

        if (!$this->option('force') && $this->modelExists($filePath, $modelName)) {
            return false;
        }

        // Generate the content for the model
        $content = $this->generateModelContent($table, $modelName);

        if (!$content) {
            return false;
        }

        $this->ensureDirectoryExists(dirname($filePath));
        (new Filesystem)->put($filePath, $content);
        $this->info("Model $modelName created.");

        return true;
    }

    protected function generateMigration(TableNode $table): bool
    {
        $migrationName = 'create_' . Str::snake($table->getName()) . '_table';

        // Generate timestamp with incremental counter
        $baseDate = now()->format('Y_m_d');
        $sequence = str_pad($this->migrationCounter, 6, '0', STR_PAD_LEFT);
        $timestamp = $baseDate . '_' . $sequence;

        $fileName = $timestamp . '_' . $migrationName . '.php';
        $filePath = database_path("migrations/$fileName");

        // Check if migration already exists
        if (!$this->option('force') && $this->migrationExists($table->getName())) {
            $this->warn("Migration for table {$table->getName()} already exists. Skipping...");
            return false;
        }

        $content = $this->generateMigrationContent($table);

        if (!$content) {
            return false;
        }

        $this->ensureDirectoryExists(dirname($filePath));
        (new Filesystem)->put($filePath, $content);
        $this->info("Migration for {$table->getName()} created.");

        // Increment counter for next migration
        $this->migrationCounter++;

        return true;
    }

    private function generateModelContent(TableNode $table, string $modelName): ?string
    {
        $columns = $table->getColumns();
        // Generate the fillable attributes for the model
        $fillable = $this->generateFillable($columns);
        // Generate the casts for the model
        $casts = $this->generateCasts($columns);
        // Generate the relations for the model
        $relations = $this->parseRelations($this->generateRelations($columns));
        // Generate the table property if the table name doesn't follow Laravel conventions
        $tableProperty = $this->generateTableProperty($table->getName(), $modelName);

        $stub = $this->getValidatedStubContent('model.stub', 'Model');
        if ($stub === null) {
            return null;
        }

        $tab = str_repeat("\t", 2);
        $castsString = '';

        if (!empty($casts)) {
            $castsString = implode(",\n$tab", array_map(
                fn($key, $value) => "'$key' => '$value'",
                array_keys($casts),
                $casts
            ));
        }

        $fillableString = '';
        if (!empty($fillable)) {
            $fillableString = implode(",\n$tab", $fillable);
        }

        return str_replace(
            ['{{ modelName }}', '{{ tableProperty }}', '{{ fillable }}', '{{ casts }}', '{{ relations }}'],
            [$modelName, $tableProperty, $fillableString, $castsString, $relations],
            $stub
        );
    }

    private function generateMigrationContent(TableNode $table): ?string
    {
        $columns = collect($table->getColumns())
            ->filter(fn(ColumnNode $col) => !in_array($col->getName(), ['created_at', 'updated_at', 'id'], true))
            ->values()
            ->toArray();

        $fields = $this->generateMigrationFields($columns);

        $stub = $this->getValidatedStubContent('migration.stub', 'Migration');
        if ($stub === null) {
            return null;
        }

        return str_replace(
            ['{{ tableName }}', '{{ fields }}'],
            [$table->getName(), $fields],
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
        $packageStubPath = __DIR__ . "/stubs/$stubName";
        return file_exists($packageStubPath) ? file_get_contents($packageStubPath) : null;
    }

    private function getValidatedStubContent(string $stubName, string $type): ?string
    {
        $stub = $this->getStubContent($stubName);
        if ($stub === null) {
            $this->error("$type stub not found.");
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
            return true;
        }
        return false;
    }

    private function migrationExists(string $tableName): bool
    {
        $migrationPattern = '*_create_' . Str::snake($tableName) . '_table.php';
        $migrationPath = database_path('migrations');

        if (!is_dir($migrationPath)) {
            return false;
        }

        $existingMigrations = glob($migrationPath . '/' . $migrationPattern);
        return !empty($existingMigrations);
    }

    private function ensureDirectoryExists(string $directory): void
    {
        if (!is_dir($directory)) {
            mkdir($directory, 0755, true);
        }
    }

    private function generateFillable(array $columns): array
    {
        // Generate the fillable attributes by filtering out primary keys and certain columns
        return collect($columns)
            ->filter(fn(ColumnNode $col) =>
                !$col->isPrimaryKey() &&
                !in_array($col->getName(), ['created_at', 'updated_at', 'id'], true)
            )
            ->map(fn(ColumnNode $col) => "'" . $col->getName() . "'")
            ->values()
            ->toArray();
    }

    private function generateCasts(array $columns): array
    {
        // Generate the casts for the model by filtering out primary keys and default types like string and integer
        return collect($columns)
            ->mapWithKeys(fn(ColumnNode $col) => [
                $col->getName() => $this->mapCastType($col->getType()->getName())
            ])
            ->filter(fn($value) => !empty($value) && !in_array($value, ['string', 'integer'], true))
            ->toArray();
    }

    private function generateRelations(array $columns): array
    {
        // Generate the relations by mapping foreign key references to related tables
        return collect($columns)
            ->filter(fn(ColumnNode $col) => $col->getRefs() && count($col->getRefs()) > 0)
            ->map(function (ColumnNode $col) {
                $relatedTable = Str::studly(Str::singular($col->getRefs()[0]->getRightTable()->getTable()));
                return [
                    'method' => Str::camel($relatedTable),
                    'relatedTable' => $relatedTable,
                    'foreignKey' => $col->getName(),
                ];
            })
            ->values()
            ->toArray();
    }

    private function parseRelations(array $relations): string
    {
        // Convert the relation array into relation methods for the model
        return collect($relations)
            ->map(function ($relation) {
                $relationMethod = "public function {$relation['method']}()";
                $relationBody = "return \$this->belongsTo({$relation['relatedTable']}::class, '{$relation['foreignKey']}');";
                return "$relationMethod
    {
        $relationBody
    }
";
            })
            ->implode("\n\t");
    }

    private function generateMigrationFields(array $columns): string
    {
        // Generate the fields for the migration by mapping column types and handling enums and foreign keys
        return collect($columns)->map(function (ColumnNode $column) {
            $type = $this->mapColumnType($column->getType()->getName());
            $name = $column->getName();

            // Handle enum types
            if (isset($this->enums[$column->getType()->getName()])) {
                $enumValues = collect($this->enums[$column->getType()->getName()]->getValues())
                    ->map(fn($value) => $value->getValue())
                    ->toArray();
                $enumString = "'" . implode("', '", $enumValues) . "'";
                $field = "\$table->enum('$name', [$enumString])";
            }
            // Handle foreign keys
            elseif ($column->getRefs() && count($column->getRefs()) > 0) {
                $referencedTable = $column->getRefs()[0]->getRightTable()->getTable();
                $field = "\$table->foreignId('$name')->constrained('$referencedTable')";
            }
            // Handle regular fields
            else {
                $field = "\$table->$type('$name')";
            }

            // Add nullable constraint (commented out due to parser library limitations)
            // if ($column->isNull()) {
            //     $field .= '->nullable()';
            // }

            // Add primary key constraint
            if ($column->isPrimaryKey()) {
                $field .= '->primary()';
            }

            return "            $field;";
        })->implode("\n");
    }

    protected function mapColumnType(string $type): string
    {
        // Map the DBML column types to Laravel migration types
        return match (strtolower($type)) {
            'int', 'integer' => 'integer',
            'text', 'longtext' => 'text',
            'bool', 'boolean' => 'boolean',
            'timestamp', 'datetime' => 'timestamp',
            'decimal' => 'decimal',
            'json' => 'json',
            'enum' => 'enum',
            'date' => 'date',
            'time' => 'time',
            'float' => 'float',
            'double' => 'double',
            'bigint' => 'bigInteger',
            'smallint' => 'smallInteger',
            'tinyint' => 'tinyInteger',
            'char' => 'char',
            'uuid' => 'uuid',
            'morph' => 'morphs',
            default => 'string',
        };
    }

    protected function mapCastType(string $type): string
    {
        // Map the DBML column types to Laravel model casts
        return match (strtolower($type)) {
            'bool', 'boolean' => 'boolean',
            'decimal', 'float', 'double' => 'float',
            'json' => 'array',
            'timestamp', 'datetime' => 'datetime',
            'date' => 'date',
            'time' => 'time',
            'int', 'integer', 'bigint', 'smallint', 'tinyint' => 'integer',
            default => '',
        };
    }

    /**
     * Generate table property if table name doesn't follow Laravel conventions
     */
    private function generateTableProperty(string $tableName, string $modelName): string
    {
        $expectedTableName = Str::snake(Str::plural($modelName));

        if ($tableName !== $expectedTableName) {
            return "protected \$table = '$tableName';\n";
        }

        return '';
    }
}

