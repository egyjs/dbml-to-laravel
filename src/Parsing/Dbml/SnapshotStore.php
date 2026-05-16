<?php

declare(strict_types=1);

namespace Egyjs\DbmlToLaravel\Parsing\Dbml;

interface SnapshotStore
{
    public function read(string $dbmlPath): ?Schema;

    public function write(string $dbmlPath, array $jsonPayload): void;

    public function exists(string $dbmlPath): bool;
}