<?php

declare(strict_types=1);

namespace Egyjs\DbmlToLaravel\Parsing\Dbml;

readonly class Schema
{
    /**
     * @param  Table[]  $tables
     * @param  array<string, EnumDefinition>  $enums
     */
    public function __construct(
        private array $tables,
        private array $enums
    ) {}

    /**
     * @return Table[]
     */
    public function getTables(): array
    {
        return $this->tables;
    }

    /**
     * @return array<string, EnumDefinition>
     */
    public function getEnums(): array
    {
        return $this->enums;
    }
}
