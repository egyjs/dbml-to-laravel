<?php

declare(strict_types=1);

namespace Egyjs\DbmlToLaravel\Parsing\Dbml;

class SchemaDiff
{
    /**
     * @param  Table[]  $addedTables  — tables present in new but not old
     * @param  string[]  $droppedTables  — table names present in old but not new
     * @param  TableDiff[]  $modifiedTables  — tables present in both with changes
     */
    public function __construct(
        public array $addedTables = [],
        public array $droppedTables = [],
        public array $modifiedTables = []
    ) {}

    public function isEmpty(): bool
    {
        return empty($this->addedTables)
            && empty($this->droppedTables)
            && empty($this->modifiedTables);
    }
}