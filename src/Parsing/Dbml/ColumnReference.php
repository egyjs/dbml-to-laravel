<?php

declare(strict_types=1);

namespace Egyjs\DbmlToLaravel\Parsing\Dbml;

readonly class ColumnReference
{
    public function __construct(
        private ReferenceTable $rightTable,
        private ?string $referencedColumn = null,
        private ?string $onDelete = null,
        private ?string $onUpdate = null
    ) {
    }

    public function getRightTable(): ReferenceTable
    {
        return $this->rightTable;
    }

    public function getReferencedColumn(): ?string
    {
        return $this->referencedColumn;
    }

    public function getOnDelete(): ?string
    {
        return $this->onDelete;
    }

    public function getOnUpdate(): ?string
    {
        return $this->onUpdate;
    }
}
