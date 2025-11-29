<?php

declare(strict_types=1);

namespace Egyjs\DbmlToLaravel\Parsing\Dbml;

class ColumnDefaultValue
{
    public function __construct(
        private readonly mixed $value,
        private readonly ?string $type = null
    ) {
    }

    public static function fromArray(null|array $payload): ?self
    {
        if ($payload === null) {
            return null;
        }

        $value = $payload['value'] ?? null;
        $type = $payload['type'] ?? null;

        if ($value === null && $type === null) {
            return null;
        }

        return new self($value, $type ? strtolower((string) $type) : null);
    }

    public function getValue(): mixed
    {
        return $this->value;
    }

    public function getType(): ?string
    {
        return $this->type;
    }

    public function isExpression(): bool
    {
        return in_array($this->type, ['expression', 'raw'], true);
    }
}
