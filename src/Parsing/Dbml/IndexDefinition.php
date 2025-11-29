<?php

declare(strict_types=1);

namespace Egyjs\DbmlToLaravel\Parsing\Dbml;

readonly class IndexDefinition
{
    /**
     * @param string[] $columns
     */
    public function __construct(
        private ?string $name,
        private array $columns,
        private bool $unique = false,
        private ?string $type = null
    ) {
    }

    public function getName(): ?string
    {
        return $this->name;
    }

    /**
     * @return string[]
     */
    public function getColumns(): array
    {
        return $this->columns;
    }

    public function isUnique(): bool
    {
        return $this->unique;
    }

    public function getType(): ?string
    {
        return $this->type;
    }
}
