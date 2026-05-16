<?php

declare(strict_types=1);

namespace Egyjs\DbmlToLaravel\Parsing\Dbml;

class TableDiff
{
    /**
     * @param  Column[]  $addedColumns
     * @param  Column[]  $droppedColumns
     * @param  ColumnPair[]  $modifiedColumns
     * @param  IndexDefinition[]  $addedIndexes
     * @param  IndexDefinition[]  $droppedIndexes
     * @param  array{column: string, reference: ColumnReference}[]  $addedForeignKeys
     * @param  array{column: string, reference: ColumnReference}[]  $droppedForeignKeys
     */
    public function __construct(
        public string $tableName = '',
        public array $addedColumns = [],
        public array $droppedColumns = [],
        public array $modifiedColumns = [],
        public array $addedIndexes = [],
        public array $droppedIndexes = [],
        public array $addedForeignKeys = [],
        public array $droppedForeignKeys = []
    ) {}

    public function isEmpty(): bool
    {
        return empty($this->addedColumns)
            && empty($this->droppedColumns)
            && empty($this->modifiedColumns)
            && empty($this->addedIndexes)
            && empty($this->droppedIndexes)
            && empty($this->addedForeignKeys)
            && empty($this->droppedForeignKeys);
    }
}