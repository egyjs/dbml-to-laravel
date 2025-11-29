<?php

declare(strict_types=1);

namespace Egyjs\DbmlToLaravel\Parsing\Dbml;

class Column
{
    /**
     * @param  ColumnReference[]  $references
     */
    public function __construct(
        private readonly string $name,
        private readonly ColumnType $type,
        private readonly bool $primaryKey,
        private readonly bool $unique,
        private readonly bool $notNull,
        private readonly bool $autoIncrement,
        private readonly ?ColumnDefaultValue $defaultValue = null,
        private array $references = []
    ) {}

    public function getName(): string
    {
        return $this->name;
    }

    public function getType(): ColumnType
    {
        return $this->type;
    }

    public function isPrimaryKey(): bool
    {
        return $this->primaryKey;
    }

    public function isUnique(): bool
    {
        return $this->unique;
    }

    public function isNotNull(): bool
    {
        return $this->notNull;
    }

    public function isAutoIncrement(): bool
    {
        return $this->autoIncrement;
    }

    /**
     * @return ColumnReference[]
     */
    public function getRefs(): array
    {
        return $this->references;
    }

    public function addReference(ColumnReference $reference): void
    {
        $this->references[] = $reference;
    }

    public function isNull(): bool
    {
        return ! $this->notNull;
    }

    public function getDefaultValue(): ?ColumnDefaultValue
    {
        return $this->defaultValue;
    }
}
