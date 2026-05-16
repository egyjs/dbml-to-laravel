<?php

declare(strict_types=1);

namespace Egyjs\DbmlToLaravel\Generation;

use Egyjs\DbmlToLaravel\Parsing\Dbml\Column;
use Egyjs\DbmlToLaravel\Parsing\Dbml\ColumnDefaultValue;
use Egyjs\DbmlToLaravel\Parsing\Dbml\ColumnReference;
use Egyjs\DbmlToLaravel\Parsing\Dbml\EnumDefinition;
use Egyjs\DbmlToLaravel\Parsing\Dbml\IndexDefinition;

class ColumnDefinitionBuilder
{
    /**
     * @param array<string, EnumDefinition> $enums
     */
    public function __construct(
        private array $enums = []
    ) {}

    /**
     * @param array<string, EnumDefinition> $enums
     */
    public function withEnums(array $enums): self
    {
        return new self($enums);
    }

    public function generateMigrationColumns(array $columns): string
    {
        return collect($columns)
            ->map(fn (Column $column) => $this->buildColumnDefinition($column))
            ->implode("\n");
    }

    public function buildColumnDefinition(Column $column): string
    {
        $name = $column->getName();
        $type = strtolower($column->getType()->getName());
        $reference = $column->getRefs()[0] ?? null;

        $useForeignId = $reference !== null && ($type === 'bigint unsigned' || $type === '');

        $field = $useForeignId
            ? "\$table->foreignId('{$name}')"
            : $this->resolveColumnBaseDefinition($column);

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

        if ($reference !== null) {
            $referencedTable = $reference->getRightTable()->getTable();
            $referencedColumn = $reference->getReferencedColumn() ?? 'id';
            $actions = $this->formatForeignKeyActions($reference);

            if ($useForeignId) {
                $constraint = $referencedColumn !== 'id'
                    ? "->constrained('{$referencedTable}', '{$referencedColumn}')"
                    : "->constrained('{$referencedTable}')";

                $field .= $constraint.$actions;

                return "            {$field};";
            }

            $foreignStmt = "\$table->foreign('{$name}')"
                ."->references('{$referencedColumn}')"
                ."->on('{$referencedTable}')"
                .$actions;

            return "            {$field};\n            {$foreignStmt};";
        }

        return "            {$field};";
    }

    public function buildColumnDefinitionWithoutIndent(Column $column): string
    {
        $def = $this->buildColumnDefinition($column);
        return preg_replace('/^            /m', '', $def);
    }

    public function buildIndexDefinition(IndexDefinition $index): ?string
    {
        if (empty($index->getColumns()) || in_array($index->getType(), ['pk', 'primary'], true)) {
            return null;
        }

        $columns = '['.implode(', ', array_map(fn (string $column) => "'{$column}'", $index->getColumns())).']';
        $method = $index->isUnique() ? 'unique' : 'index';
        $name = $index->getName() ? ", '{$index->getName()}'" : '';

        return "            \$table->{$method}({$columns}{$name});";
    }

    public function resolveColumnBaseDefinition(Column $column): string
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
            'bigint unsigned' => "\$table->unsignedBigInteger('$name')",
            'int unsigned', 'integer unsigned' => "\$table->unsignedInteger('$name')",
            'smallint unsigned' => "\$table->unsignedSmallInteger('$name')",
            'tinyint unsigned' => "\$table->unsignedTinyInteger('$name')",
            'mediumint unsigned' => "\$table->unsignedMediumInteger('$name')",
            'bigint', 'bigserial' => "\$table->bigInteger('$name')",
            'smallint', 'smallserial' => "\$table->smallInteger('$name')",
            'tinyint' => "\$table->tinyInteger('$name')",
            'mediumint' => "\$table->mediumInteger('$name')",
            'serial', 'int', 'integer' => "\$table->integer('$name')",
            default => "\$table->string('$name')",
        };
    }

    public function formatDefaultValue(?ColumnDefaultValue $default): string
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

    public function formatForeignKeyActions(ColumnReference $reference): string
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

    public function mapForeignAction(string $operation, string $action): string
    {
        $operationMethod = ucfirst($operation);

        return match (strtolower($action)) {
            'cascade' => "->cascadeOn{$operationMethod}()",
            'restrict' => "->restrictOn{$operationMethod}()",
            'set null' => "->nullOn{$operationMethod}()",
            default => '',
        };
    }

    public function isAutoIncrementingPrimaryKey(Column $column): bool
    {
        return $column->isPrimaryKey() && $column->isAutoIncrement();
    }
}