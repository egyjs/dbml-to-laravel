<?php

declare(strict_types=1);

namespace Egyjs\DbmlToLaravel\Generation;

use Egyjs\DbmlToLaravel\Parsing\Dbml\Column;
use Egyjs\DbmlToLaravel\Parsing\Dbml\ColumnPair;
use Egyjs\DbmlToLaravel\Parsing\Dbml\ColumnReference;
use Egyjs\DbmlToLaravel\Parsing\Dbml\IndexDefinition;
use Egyjs\DbmlToLaravel\Parsing\Dbml\TableDiff;

class AlterMigrationGenerator
{
    public function __construct(
        private ColumnDefinitionBuilder $columnBuilder
    ) {}

    public function generate(TableDiff $diff, string $stubContent): string
    {
        $upLines = [];
        $downLines = [];

        foreach ($diff->addedColumns as $column) {
            $upLines[] = '            ' . $this->columnBuilder->buildColumnDefinitionWithoutIndent($column) . ';';
            $downLines[] = "            \$table->dropColumn('{$column->getName()}');";
        }

        foreach ($diff->droppedColumns as $column) {
            $upLines[] = "            \$table->dropColumn('{$column->getName()}');";
            $downLines[] = '            ' . $this->columnBuilder->buildColumnDefinitionWithoutIndent($column) . ';';
        }

        foreach ($diff->modifiedColumns as $pair) {
            $upLines[] = '            ' . $this->buildChangeColumn($pair->new) . ';';
            $downLines[] = '            ' . $this->buildChangeColumn($pair->old) . ';';
        }

        foreach ($diff->addedIndexes as $index) {
            $indexDef = $this->columnBuilder->buildIndexDefinition($index);
            if ($indexDef !== null) {
                $upLines[] = $indexDef;
                $downLines[] = $this->buildDropIndex($index);
            }
        }

        foreach ($diff->droppedIndexes as $index) {
            $downLines[] = $this->columnBuilder->buildIndexDefinition($index) ?? '';
            $upLines[] = $this->buildDropIndex($index);
        }

        foreach ($diff->addedForeignKeys as $fkData) {
            $upLines[] = $this->buildAddForeignKey($fkData);
            $downLines[] = $this->buildDropForeignKey($fkData);
        }

        foreach ($diff->droppedForeignKeys as $fkData) {
            $upLines[] = $this->buildDropForeignKey($fkData);
            $downLines[] = $this->buildAddForeignKey($fkData);
        }

        $upContent = implode("\n", array_filter($upLines));
        $downContent = implode("\n", array_filter($downLines));

        return str_replace(
            ['{{ tableName }}', '{{ upColumns }}', '{{ downColumns }}'],
            [$diff->tableName, $upContent, $downContent],
            $stubContent
        );
    }

    private function buildChangeColumn(Column $column): string
    {
        $def = $this->columnBuilder->resolveColumnBaseDefinition($column);

        if ($column->isNull()) {
            $def .= '->nullable()';
        }

        if ($column->getDefaultValue() !== null) {
            $def .= '->default(' . $this->columnBuilder->formatDefaultValue($column->getDefaultValue()) . ')';
        }

        if ($column->isUnique() && !$column->isPrimaryKey()) {
            $def .= '->unique()';
        }

        $def .= '->change()';

        return $def;
    }

    /**
     * @param IndexDefinition $index
     * @return string
     */
    private function buildDropIndex(IndexDefinition $index): string
    {
        if ($index->getName()) {
            return "            \$table->dropIndex('{$index->getName()}');";
        }

        $columns = array_map(fn (string $col) => "'{$col}'", $index->getColumns());
        $method = $index->isUnique() ? 'dropUnique' : 'dropIndex';

        return "            \$table->{$method}([" . implode(', ', $columns) . ']);';
    }

    /**
     * @param array{column: string, reference: ColumnReference} $fkData
     * @return string
     */
    private function buildAddForeignKey(array $fkData): string
    {
        $columnName = $fkData['column'];
        $ref = $fkData['reference'];
        $referencedTable = $ref->getRightTable()->getTable();
        $referencedColumn = $ref->getReferencedColumn() ?? 'id';
        $actions = $this->columnBuilder->formatForeignKeyActions($ref);

        return "            \$table->foreign('{$columnName}')"
            . "->references('{$referencedColumn}')"
            . "->on('{$referencedTable}')"
            . $actions . ';';
    }

    /**
     * @param array{column: string, reference: ColumnReference} $fkData
     * @return string
     */
    private function buildDropForeignKey(array $fkData): string
    {
        $columnName = $fkData['column'];

        return "            \$table->dropForeign(['{$columnName}']);";
    }
}