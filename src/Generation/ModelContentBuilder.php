<?php

declare(strict_types=1);

namespace Egyjs\DbmlToLaravel\Generation;

use Egyjs\DbmlToLaravel\Parsing\Dbml\Column;
use Egyjs\DbmlToLaravel\Parsing\Dbml\Schema;
use Egyjs\DbmlToLaravel\Parsing\Dbml\Table;
use Illuminate\Support\Str;

class ModelContentBuilder
{
    public function generateFillable(array $columns): array
    {
        return collect($columns)
            ->filter(fn (Column $col) => ! $col->isPrimaryKey() &&
                ! in_array($col->getName(), ['created_at', 'updated_at', 'id'], true)
            )
            ->map(fn (Column $col) => "'".$col->getName()."'")
            ->values()
            ->toArray();
    }

    public function generateCasts(array $columns): array
    {
        return collect($columns)
            ->mapWithKeys(fn (Column $col) => [
                $col->getName() => $this->mapCastType($col),
            ])
            ->filter(fn ($value) => ! empty($value) && ! in_array($value, ['string', 'integer'], true))
            ->toArray();
    }

    public function mapCastType(Column $column): string
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

    public function generateTableProperty(string $tableName, string $modelName): string
    {
        $expectedTableName = Str::snake(Str::plural($modelName));

        if ($tableName !== $expectedTableName) {
            return "protected \$table = '$tableName';";
        }

        return '';
    }

    public function generateBelongsToRelations(array $columns): array
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

    public function generateHasManyRelations(Table $table, Schema $schema): array
    {
        $relations = [];

        foreach ($schema->getTables() as $candidate) {
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

    public function parseRelations(array $relations): string
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

    public function formatRelationBody(array $relation): string
    {
        return match ($relation['type']) {
            'hasMany' => "return \$this->hasMany({$relation['relatedTable']}::class, '{$relation['foreignKey']}', '{$relation['localKey']}');",
            default => "return \$this->belongsTo({$relation['relatedTable']}::class, '{$relation['foreignKey']}'".($relation['ownerKey'] ? ", '{$relation['ownerKey']}'" : '').');',
        };
    }

    public function generateModelContent(Table $table, string $modelName, Schema $schema, string $stub): ?string
    {
        $columns = $table->getColumns();
        $fillable = $this->generateFillable($columns);
        $casts = $this->generateCasts($columns);
        $relations = $this->parseRelations(array_merge(
            $this->generateBelongsToRelations($columns),
            $this->generateHasManyRelations($table, $schema)
        ));
        $tableProperty = $this->generateTableProperty($table->getName(), $modelName);

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
}