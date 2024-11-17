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
    protected $signature = 'generate:dbml {file}';
    protected $description = 'Generate models and migrations from a DBML file';
    /**
     * @var \Butschster\Dbml\Ast\EnumNode[]
     */
    private array $enums;

    private const FORBIDDEN_MODEL_NAMES = [
        'Class', 'Trait', 'Interface', 'Namespace', 'Object', 'Resource', 'String', 'Array', 'Float', 'Int', 'Bool', 'Boolean', 'Null', 'Void', 'Iterable', 'Parent', 'Self', 'Static', 'Mixed'
    ];

    public function handle()
    {
        $file = $this->argument('file');

        // Check if the provided file exists
        if (!file_exists($file)) {
            $this->error("File not found: $file");
            return;
        }

        // Create the DBML parser and parse the schema from the file
        $parser = DbmlParserFactory::create();
        $schema = $parser->parse(file_get_contents($file));

        // Retrieve enums from the schema for use in migrations
        $this->enums = $schema->getEnums();
        foreach ($schema->getTables() as $table) {
            // Generate model and migration for each table
            $this->generateModel($table);
            $this->generateMigration($table);
        }

        $this->info('Models and migrations generated successfully.');
    }

    protected function generateModel(TableNode $table): void
    {
        $modelName = Str::studly(Str::singular($table->getName()));

        // Check if the model name is a reserved PHP keyword
        if ($this->isForbiddenModelName($modelName)) {
            $this->error("Model \"$modelName\" for table \"{$table->getName()}\" cannot be created because it is a reserved PHP keyword.");
            return;
        }

        $filePath = app_path("Models/$modelName.php");

        // Skip model creation if the file already exists
        if ($this->modelExists($filePath, $modelName)) {
            return;
        }

        // Generate the content for the model
        $content = $this->generateModelContent($table, $modelName);

        // Write the generated content to the model file
        if ($content) {
            (new Filesystem)->put($filePath, $content);
            $this->info("Model $modelName created.");
        }
    }

    protected function generateMigration(TableNode $table): void
    {
        $migrationName = 'create_' . Str::snake($table->getName()) . '_table';
        $fileName = date('Y_m_d_His') . '_' . $migrationName . '.php';
        $filePath = database_path("migrations/$fileName");

        // Generate the content for the migration
        $content = $this->generateMigrationContent($table, $migrationName);

        // Write the generated content to the migration file
        if ($content) {
            (new Filesystem)->put($filePath, $content);
            $this->info("Migration for {$table->getName()} created.");
        }
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

        // Retrieve the model stub content
        $stub = $this->getStubContent('model.stub');
        if ($stub === null) {
            $this->error("Model stub not found.");
            return null;
        }

        $tab = str_repeat("\t", 2);
        // Create the casts string by formatting the key-value pairs
        $castsString = implode(",\n$tab", array_map(fn($key, $value) => "'$key' => '$value'", array_keys($casts), $casts));
        // Replace placeholders in the stub with the actual content
        return str_replace(
            ['{{ modelName }}', '{{ fillable }}', '{{ casts }}', '{{ relations }}'],
            [$modelName, implode(",\n$tab", $fillable), $castsString, $relations],
            $stub
        );
    }

    private function generateMigrationContent(TableNode $table, string $migrationName): ?string
    {
        $columns = $table->getColumns();
        // Filter out certain columns such as created_at, updated_at, and id
        $columns = collect($columns)
            ->filter(fn(ColumnNode $col) => !in_array($col->getName(), ['created_at', 'updated_at','id']))
            ->values()->toArray();
        // Generate the fields for the migration
        $fields = $this->generateMigrationFields($columns);

        // Retrieve the migration stub content
        $stub = $this->getStubContent('migration.stub');
        if ($stub === null) {
            $this->error("Migration stub not found.");
            return null;
        }

        // Replace placeholders in the stub with the actual content
        return str_replace(
            ['{{ migrationName }}', '{{ tableName }}', '{{ fields }}'],
            [$migrationName, $table->getName(), $fields],
            $stub
        );
    }

    private function getStubContent(string $stubName): ?string
    {
        $stubPath = __DIR__ . "/stubs/$stubName";
        // Retrieve the content of the stub file if it exists
        return file_exists($stubPath) ? file_get_contents($stubPath) : null;
    }

    private function isForbiddenModelName(string $modelName): bool
    {
        // Check if the model name is in the list of forbidden names
        return in_array($modelName, self::FORBIDDEN_MODEL_NAMES);
    }

    private function modelExists(string $filePath, string $modelName): bool
    {
        // Check if the model file already exists
        if (file_exists($filePath)) {
            $this->warn("Model $modelName already exists. Skipping...");
            return true;
        }
        return false;
    }

    private function generateFillable(array $columns): array
    {
        // Generate the fillable attributes by filtering out primary keys and certain columns
        return collect($columns)
            ->filter(fn(ColumnNode $col) => !$col->isPrimaryKey() && !in_array($col->getName(), ['created_at', 'updated_at', 'id']))
            ->map(fn(ColumnNode $col) => "'" . $col->getName() . "'")
            ->toArray();
    }

    private function generateCasts(array $columns): array
    {
        // Generate the casts for the model by filtering out primary keys and default types like string and integer
        return collect($columns)
            ->mapWithKeys(fn(ColumnNode $col) => [
                $col->getName() => $this->mapCastType($col->getType()->getName())
            ])
            ->filter(fn($value) => !$value && !in_array($value, ['string', 'integer']))
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

            // Handle enum types by retrieving possible values
            if (isset($this->enums[$column->getType()->getName()])) {
                $enumValues = $this->enums[$column->getType()->getName()]->getValues();
                $enumValues = array_map(fn($value) => $value->getValue(), $enumValues);
                $enumString = implode("', '", $enumValues);
                $field = "\$table->enum('$name', ['$enumString'])";
            } else if ($column->getRefs() && count($column->getRefs()) > 0) {
                // Handle foreign keys by adding references to other tables
                $referencedTable = $column->getRefs()[0]->getRightTable()->getTable();
                $field = "\$table->foreignId('$name')->constrained('$referencedTable')";
            } else {
                // Handle other field types
                $field = "\$table->$type('$name')";
            }

            // TODO: Add nullable (not working because of the parser library)
//            if ($column->isNull()) {
//                $field .= '->nullable()';
//            }

            // Mark the field as primary if it is a primary key
            if ($column->isPrimaryKey()) {
                $field .= '->primary()';
            }

            return "$field;\n            ";
        })->implode('');
    }

    protected function mapColumnType($type): string
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
            'morph' => 'morphs',
            default => 'string',
        };
    }

    protected function mapCastType($type): string
    {
        // Map the DBML column types to Laravel model casts
        return match (strtolower($type)) {
            'bool', 'boolean' => 'boolean',
            'decimal', 'float' => 'float',
            'json' => 'array',
            'timestamp', 'datetime', 'date' => 'datetime',
            default => 'string',
        };
    }
}

