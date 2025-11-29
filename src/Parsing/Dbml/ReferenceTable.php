<?php

declare(strict_types=1);

namespace Egyjs\DbmlToLaravel\Parsing\Dbml;

readonly class ReferenceTable
{
    public function __construct(
        private string  $table,
        private ?string $schema = null
    ) {
    }

    public function getTable(): string
    {
        return $this->table;
    }

    public function getSchema(): ?string
    {
        return $this->schema;
    }
}
