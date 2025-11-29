<?php

declare(strict_types=1);

namespace Egyjs\DbmlToLaravel\Parsing\Dbml;

readonly class EnumDefinition
{
    /**
     * @param  EnumValue[]  $values
     */
    public function __construct(
        private string $name,
        private array $values
    ) {}

    public function getName(): string
    {
        return $this->name;
    }

    /**
     * @return EnumValue[]
     */
    public function getValues(): array
    {
        return $this->values;
    }
}
