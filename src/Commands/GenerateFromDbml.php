<?php

namespace Egyjs\DbmlToLaravel\Commands;

use Egyjs\DbmlToLaravel\Parsing\Dbml\Column;
use Egyjs\DbmlToLaravel\Parsing\Dbml\ColumnDefaultValue;
use Egyjs\DbmlToLaravel\Parsing\Dbml\ColumnReference;
use Egyjs\DbmlToLaravel\Parsing\Dbml\EnumDefinition;
use Egyjs\DbmlToLaravel\Parsing\Dbml\IndexDefinition;
use Egyjs\DbmlToLaravel\Parsing\Dbml\Schema;
use Egyjs\DbmlToLaravel\Parsing\Dbml\Table;
use Egyjs\DbmlToLaravel\Parsing\NodeDbmlParser;
use Illuminate\Console\Command;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Str;
use Throwable;

class GenerateFromDbml extends Command
{
    protected $signature = 'generate:dbml {file} {--force : Overwrite existing files}';

    protected $description = 'Generate models and migrations from a DBML file';

    /**
     * @var array<string, EnumDefinition>
     */
    private array $enums = [];

    private Schema $schema;

    private const FORBIDDEN_MODEL_NAMES = [
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

        // Check if the provided file exists
        if (! file_exists($file)) {
            $this->error("File not found: $file");

            return static::FAILURE;
        }

        try {
            $parser = new NodeDbmlParser;
            $schema = $parser->parse($file);
        } catch (Throwable $e) {
            $this->error('Failed to parse DBML file: '.$e->getMessage());

            return static::FAILURE;
        }

        $this->schema = $schema;
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

        return static::SUCCESS;
    }

    protected function generateModel(Table $table): bool
    {
        $modelName = Str::studly(Str::singular($table->getName()));

        // Check if the model name is a reserved PHP keyword
        if ($this->isForbiddenModelName($modelName)) {
            $this->error("Model \"$modelName\" for table \"{$table->getName()}\" cannot be created because it is a reserved PHP keyword.");

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

        $this->ensureDirectoryExists(dirname($filePath));
        (new Filesystem)->put($filePath, $content);
        $this->info("Model $modelName created.");

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

            return false;
        }

        $content = $this->generateMigrationContent($table);

        if (! $content) {
            return false;
        }

        $this->ensureDirectoryExists(dirname($filePath));
        (new Filesystem)->put($filePath, $content);
        $this->info("Migration for {$table->getName()} created.");

        // Increment counter for next migration
        $this->migrationCounter++;

        return true;
    }

    private function generateModelContent(Table $table, string $modelName): ?string
    {
        $columns = $table->getColumns();
        // Generate the fillable attributes for the model
        $fillable = $this->generateFillable($columns);
        // Generate the casts for the model
        $casts = $this->generateCasts($columns);
        // Generate the relations for the model
        $relations = $this->parseRelations(array_merge(
            $this->generateBelongsToRelations($columns),
            $this->generateHasManyRelations($table)
        ));
        // Generate the table property if the table name doesn't follow Laravel conventions
        $tableProperty = $this->generateTableProperty($table->getName(), $modelName);

        $stub = $this->getValidatedStubContent('model.stub', 'Model');
        if ($stub === null) {
            return null;
        }

        $tab = str_repeat("\t", 2);
        $castsString = '';

        if (! empty($casts)) {
            $castsString = implode(",\n$tab", array_map(
                fn ($key, $value) => "'$key' => '$value'",
                array_keys($casts),
                $casts
            ));
        }

        $fillableString = '';
        if (! empty($fillable)) {
            $fillableString = implode(",\n$tab", $fillable);
        }

        return str_replace(
            ['{{ modelName }}', '{{ tableProperty }}', '{{ fillable }}', '{{ casts }}', '{{ relations }}'],
            [$modelName, $tableProperty, $fillableString, $castsString, $relations],
            $stub
        );
    }

    private function generateMigrationContent(Table $table): ?string
    {
        $columnDefinitions = $this->generateMigrationColumns($table->getColumns());
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
        $migrationPattern = '*_create_'.Str::snake($tableName).'_table.php';
        $migrationPath = database_path('migrations');

        if (! is_dir($migrationPath)) {
            return false;
        }

        $existingMigrations = glob($migrationPath.'/'.$migrationPattern);

        return ! empty($existingMigrations);
    }

    private function ensureDirectoryExists(string $directory): void
    {
        if (! is_dir($directory)) {
            mkdir($directory, 0755, true);
        }
    }

    private function generateFillable(array $columns): array
    {
        // Generate the fillable attributes by filtering out primary keys and certain columns
        return collect($columns)
            ->filter(fn (Column $col) => ! $col->isPrimaryKey() &&
                ! in_array($col->getName(), ['created_at', 'updated_at', 'id'], true)
            )
            ->map(fn (Column $col) => "'".$col->getName()."'")
            ->values()
            ->toArray();
    }

    private function generateCasts(array $columns): array
    {
        return collect($columns)
            ->mapWithKeys(fn (Column $col) => [
                $col->getName() => $this->mapCastType($col),
            ])
            ->filter(fn ($value) => ! empty($value) && ! in_array($value, ['string', 'integer'], true))
            ->toArray();
    }

    private function generateBelongsToRelations(array $columns): array
    {
        return collect($columns)
            ->filter(fn (Column $col) => count($col->getRefs()) > 0)
            ->map(function (Column $col) {
                $reference = $col->getRefs()[0];
                $relatedTable = Str::studly(Str::singular($reference->getRightTable()->getTable()));

                return [
                    'type' => 'belongsTo',
                    'method' => Str::camel($relatedTable),
                    'relatedTable' => $relatedTable,
                    'foreignKey' => $col->getName(),
                    'ownerKey' => $reference->getReferencedColumn(),
                ];
            })
            ->values()
            ->toArray();
    }

    private function generateHasManyRelations(Table $table): array
    {
        $relations = [];

        foreach ($this->schema->getTables() as $candidate) {
            foreach ($candidate->getColumns() as $column) {
                foreach ($column->getRefs() as $reference) {
                    if (strcasecmp($reference->getRightTable()->getTable(), $table->getName()) !== 0) {
                        continue;
                    }

                    $relatedTable = Str::studly(Str::singular($candidate->getName()));
                    $method = Str::camel(Str::studly(Str::plural($relatedTable)));
                    $key = $method.':'.$candidate->getName();

                    if (isset($relations[$key])) {
                        continue;
                    }

                    $relations[$key] = [
                        'type' => 'hasMany',
                        'method' => $method,
                        'relatedTable' => $relatedTable,
                        'foreignKey' => $column->getName(),
                        'localKey' => $reference->getReferencedColumn() ?? 'id',
                    ];
                }
            }
        }

        return array_values($relations);
    }

    private function parseRelations(array $relations): string
    {
        if (empty($relations)) {
            return '';
        }

        return collect($relations)
            ->map(function (array $relation) {
                $methodSignature = "    public function {$relation['method']}()";
                $body = $this->formatRelationBody($relation);

                return "$methodSignature\n    {\n        $body\n    }\n";
            })
            ->implode("\n");
    }

    private function formatRelationBody(array $relation): string
    {
        return match ($relation['type']) {
            'hasMany' => "return \$this->hasMany({$relation['relatedTable']}::class, '{$relation['foreignKey']}', '{$relation['localKey']}');",
            default => "return \$this->belongsTo({$relation['relatedTable']}::class, '{$relation['foreignKey']}'".($relation['ownerKey'] ? ", '{$relation['ownerKey']}'" : '').');',
        };
    }

    private function generateMigrationColumns(array $columns): string
    {
        return collect($columns)
            ->map(fn (Column $column) => $this->buildColumnDefinition($column))
            ->implode("\n");
    }

    private function generateIndexDefinitions(Table $table): string
    {
        $definitions = collect($table->getIndexes())
            ->map(fn (IndexDefinition $index) => $this->buildIndexDefinition($index))
            ->filter()
            ->implode("\n");

        return $definitions === '' ? '' : "\n".$definitions;
    }

    private function buildColumnDefinition(Column $column): string
    {
        $field = $this->resolveColumnBaseDefinition($column);

        if ($column->isNull()) {
            $field .= '->nullable()';
        }

        if ($column->getDefaultValue() !== null) {
            $field .= '->default('.$this->formatDefaultValue($column->getDefaultValue()).')';
        }

        if ($column->isUnique() && ! $column->isPrimaryKey()) {
            $field .= '->unique()';
        }

        if ($column->isPrimaryKey() && ! $this->isAutoIncrementingPrimaryKey($column)) {
            $field .= '->primary()';
        }

        return "            {$field};";
    }

    private function buildIndexDefinition(IndexDefinition $index): ?string
    {
        if (empty($index->getColumns()) || in_array($index->getType(), ['pk', 'primary'], true)) {
            return null;
        }

        $columns = '['.implode(', ', array_map(fn (string $column) => "'{$column}'", $index->getColumns())).']';
        $method = $index->isUnique() ? 'unique' : 'index';
        $name = $index->getName() ? ", '{$index->getName()}'" : '';

        return "            \$table->{$method}({$columns}{$name});";
    }

    private function resolveColumnBaseDefinition(Column $column): string
    {
        $name = $column->getName();
        $type = strtolower($column->getType()->getName());
        $args = $column->getType()->getArgs();

        if ($this->isAutoIncrementingPrimaryKey($column)) {
            return match (true) {
                str_contains($type, 'big') => "\$table->bigIncrements('$name')",
                str_contains($type, 'small') => "\$table->smallIncrements('$name')",
                default => "\$table->increments('$name')",
            };
        }

        if (isset($this->enums[$column->getType()->getName()])) {
            $enumValues = collect($this->enums[$column->getType()->getName()]->getValues())
                ->map(fn ($value) => "'{$value->getValue()}'")
                ->implode(', ');

            return "\$table->enum('$name', [$enumValues])";
        }

        if ($reference = $column->getRefs()[0] ?? null) {
            $referencedTable = $reference->getRightTable()->getTable();
            $referencedColumn = $reference->getReferencedColumn();
            $constraint = $referencedColumn && $referencedColumn !== 'id'
                ? "->constrained('{$referencedTable}', '{$referencedColumn}')"
                : "->constrained('{$referencedTable}')";

            $definition = "\$table->foreignId('$name'){$constraint}";

            return $definition.$this->formatForeignKeyActions($reference);
        }

        $stringLength = max(1, (int) ($args[0] ?? 255));
        $charLength = max(1, (int) ($args[0] ?? 255));
        $precision = max(1, (int) ($args[0] ?? 8));
        $scale = max(0, (int) ($args[1] ?? 2));

        return match ($type) {
            'varchar', 'string' => "\$table->string('$name', {$stringLength})",
            'char' => "\$table->char('$name', {$charLength})",
            'uuid' => "\$table->uuid('$name')",
            'text', 'longtext' => "\$table->text('$name')",
            'json', 'jsonb' => "\$table->json('$name')",
            'timestamptz', 'timestampz', 'timestamp with time zone' => "\$table->timestampTz('$name')",
            'timestamp', 'datetime' => "\$table->timestamp('$name')",
            'date' => "\$table->date('$name')",
            'time' => "\$table->time('$name')",
            'boolean', 'bool' => "\$table->boolean('$name')",
            'double' => "\$table->double('$name')",
            'float' => "\$table->float('$name')",
            'numeric', 'decimal' => "\$table->decimal('$name', {$precision}, {$scale})",
            'bigint', 'bigserial' => "\$table->bigInteger('$name')",
            'smallint', 'smallserial' => "\$table->smallInteger('$name')",
            'tinyint' => "\$table->tinyInteger('$name')",
            'serial', 'int', 'integer' => "\$table->integer('$name')",
            default => "\$table->string('$name')",
        };
    }

    private function formatDefaultValue(?ColumnDefaultValue $default): string
    {
        if ($default === null) {
            return 'null';
        }

        $value = $default->getValue();

        if ($default->isExpression() && is_string($value)) {
            return "DB::raw('".addslashes($value)."')";
        }

        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }

        if (is_numeric($value)) {
            return (string) $value;
        }

        if ($value === null) {
            return 'null';
        }

        return "'".addslashes((string) $value)."'";
    }

    private function formatForeignKeyActions(ColumnReference $reference): string
    {
        $actions = '';

        if ($reference->getOnDelete()) {
            $actions .= $this->mapForeignAction('delete', $reference->getOnDelete());
        }

        if ($reference->getOnUpdate()) {
            $actions .= $this->mapForeignAction('update', $reference->getOnUpdate());
        }

        return $actions;
    }

    private function mapForeignAction(string $operation, string $action): string
    {
        $operationMethod = ucfirst($operation);

        return match (strtolower($action)) {
            'cascade' => "->cascadeOn{$operationMethod}()",
            'restrict' => "->restrictOn{$operationMethod}()",
            'set null' => "->nullOn{$operationMethod}()",
            default => '',
        };
    }

    private function isAutoIncrementingPrimaryKey(Column $column): bool
    {
        return $column->isPrimaryKey() && $column->isAutoIncrement();
    }

    private function mapCastType(Column $column): string
    {
        $type = strtolower($column->getType()->getName());
        $args = $column->getType()->getArgs();

        return match ($type) {
            'bool', 'boolean' => 'boolean',
            'json', 'jsonb' => 'array',
            'timestamp', 'datetime', 'timestamptz', 'timestampz', 'timestamp with time zone' => 'datetime',
            'date' => 'date',
            'time' => 'datetime',
            'int', 'integer', 'bigint', 'smallint', 'tinyint' => 'integer',
            'decimal', 'numeric' => isset($args[1]) ? 'decimal:'.(int) $args[1] : 'float',
            'double', 'float' => 'float',
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
            return "protected \$table = '$tableName';";
        }

        return '';
    }
}
