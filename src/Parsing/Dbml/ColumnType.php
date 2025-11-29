<?php

declare(strict_types=1);

namespace Egyjs\DbmlToLaravel\Parsing\Dbml;

readonly class ColumnType
{
    /**
     * @param string[] $args
     */
    public function __construct(
        private string  $name,
        private ?string $schemaName = null,
        private array   $args = []
    ) {
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function getSchemaName(): ?string
    {
        return $this->schemaName;
    }

    /**
     * @return string[]
     */
    public function getArgs(): array
    {
        return $this->args;
    }
}
