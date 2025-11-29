<?php

declare(strict_types=1);

namespace Egyjs\DbmlToLaravel\Parsing\Dbml;

readonly class Table
{
    /**
     * @param Column[] $columns
     */
    public function __construct(
        private string $name,
        private string $schema,
        private array  $columns,
        private array  $indexes = []
    ) {
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function getSchema(): string
    {
        return $this->schema;
    }

    /**
     * @return Column[]
     */
    public function getColumns(): array
    {
        return $this->columns;
    }

    /**
     * @return IndexDefinition[]
     */
    public function getIndexes(): array
    {
        return $this->indexes;
    }
}
