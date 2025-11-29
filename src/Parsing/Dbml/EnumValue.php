<?php

declare(strict_types=1);

namespace Egyjs\DbmlToLaravel\Parsing\Dbml;

readonly class EnumValue
{
    public function __construct(private string $value) {}

    public function getValue(): string
    {
        return $this->value;
    }
}
