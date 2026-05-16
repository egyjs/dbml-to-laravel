<?php

declare(strict_types=1);

namespace Egyjs\DbmlToLaravel\Parsing\Dbml;

class SchemaDiffer
{
    public static function diff(Schema $old, Schema $new): SchemaDiff
    {
        $oldTableMap = self::indexTablesByName($old->getTables());
        $newTableMap = self::indexTablesByName($new->getTables());

        $addedTables = [];
        $droppedTables = [];
        $modifiedTables = [];

        foreach ($newTableMap as $name => $table) {
            if (! isset($oldTableMap[$name])) {
                $addedTables[] = $table;
                continue;
            }

            $tableDiff = self::diffTable($oldTableMap[$name], $table);
            if (! $tableDiff->isEmpty()) {
                $modifiedTables[] = $tableDiff;
            }
        }

        foreach ($oldTableMap as $name => $table) {
            if (! isset($newTableMap[$name])) {
                $droppedTables[] = $name;
            }
        }

        return new SchemaDiff($addedTables, $droppedTables, $modifiedTables);
    }

    /**
     * @param  Table[]  $tables
     * @return array<string, Table>
     */
    private static function indexTablesByName(array $tables): array
    {
        $map = [];
        foreach ($tables as $table) {
            $map[strtolower($table->getName())] = $table;
        }

        return $map;
    }

    private static function diffTable(Table $old, Table $new): TableDiff
    {
        $oldColumnMap = self::indexColumnsByName($old->getColumns());
        $newColumnMap = self::indexColumnsByName($new->getColumns());

        $addedColumns = [];
        $droppedColumns = [];
        $modifiedColumns = [];

        foreach ($newColumnMap as $name => $column) {
            if (! isset($oldColumnMap[$name])) {
                $addedColumns[] = $column;
                continue;
            }

            if (self::columnChanged($oldColumnMap[$name], $column)) {
                $modifiedColumns[] = new ColumnPair($oldColumnMap[$name], $column);
            }
        }

        foreach ($oldColumnMap as $name => $column) {
            if (! isset($newColumnMap[$name])) {
                $droppedColumns[] = $column;
            }
        }

        [$addedIndexes, $droppedIndexes] = self::diffIndexes($old->getIndexes(), $new->getIndexes());
        [$addedFks, $droppedFks] = self::diffForeignKeys($old->getColumns(), $new->getColumns());

        return new TableDiff(
            $new->getName(),
            $addedColumns,
            $droppedColumns,
            $modifiedColumns,
            $addedIndexes,
            $droppedIndexes,
            $addedFks,
            $droppedFks
        );
    }

    /**
     * @param  Column[]  $columns
     * @return array<string, Column>
     */
    private static function indexColumnsByName(array $columns): array
    {
        $map = [];
        foreach ($columns as $column) {
            $map[strtolower($column->getName())] = $column;
        }

        return $map;
    }

    private static function columnChanged(Column $old, Column $new): bool
    {
        return strtolower($old->getType()->getName()) !== strtolower($new->getType()->getName())
            || $old->getType()->getArgs() !== $new->getType()->getArgs()
            || $old->isNull() !== $new->isNull()
            || $old->getDefaultValue() !== $new->getDefaultValue()
            || $old->isUnique() !== $new->isUnique()
            || $old->isPrimaryKey() !== $new->isPrimaryKey()
            || $old->isAutoIncrement() !== $new->isAutoIncrement();
    }

    /**
     * @param  IndexDefinition[]  $oldIndexes
     * @param  IndexDefinition[]  $newIndexes
     * @return array{IndexDefinition[], IndexDefinition[]}
     */
    private static function diffIndexes(array $oldIndexes, array $newIndexes): array
    {
        $oldIndexMap = self::indexIndexesByColumns($oldIndexes);
        $newIndexMap = self::indexIndexesByColumns($newIndexes);

        $added = [];
        $dropped = [];

        foreach ($newIndexMap as $key => $index) {
            if (! isset($oldIndexMap[$key])) {
                $added[] = $index;
            }
        }

        foreach ($oldIndexMap as $key => $index) {
            if (! isset($newIndexMap[$key])) {
                $dropped[] = $index;
            }
        }

        return [$added, $dropped];
    }

    /**
     * @param  IndexDefinition[]  $indexes
     * @return array<string, IndexDefinition>
     */
    private static function indexIndexesByColumns(array $indexes): array
    {
        $map = [];
        foreach ($indexes as $index) {
            if (empty($index->getColumns()) || in_array($index->getType(), ['pk', 'primary'], true)) {
                continue;
            }
            $key = implode(',', array_map('strtolower', $index->getColumns())) . ($index->isUnique() ? ':unique' : '');
            $map[$key] = $index;
        }

        return $map;
    }

    /**
     * @param  Column[]  $oldColumns
     * @param  Column[]  $newColumns
     * @return array{array{column: string, reference: ColumnReference}[], array{column: string, reference: ColumnReference}[]}
     */
    private static function diffForeignKeys(array $oldColumns, array $newColumns): array
    {
        $oldFkMap = self::collectForeignKeys($oldColumns);
        $newFkMap = self::collectForeignKeys($newColumns);

        $added = [];
        $dropped = [];

        foreach ($newFkMap as $key => $fkData) {
            if (! isset($oldFkMap[$key])) {
                $added[] = $fkData;
            }
        }

        foreach ($oldFkMap as $key => $fkData) {
            if (! isset($newFkMap[$key])) {
                $dropped[] = $fkData;
            }
        }

        return [$added, $dropped];
    }

    /**
     * @param  Column[]  $columns
     * @return array<string, array{column: string, reference: ColumnReference}>
     */
    private static function collectForeignKeys(array $columns): array
    {
        $map = [];
        foreach ($columns as $column) {
            foreach ($column->getRefs() as $ref) {
                $refTable = $ref->getRightTable()->getTable();
                $refCol = $ref->getReferencedColumn() ?? 'id';
                $key = strtolower($column->getName()) . '->' . strtolower($refTable) . '.' . strtolower($refCol);
                $map[$key] = ['column' => $column->getName(), 'reference' => $ref];
            }
        }

        return $map;
    }
}