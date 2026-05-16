<?php

declare(strict_types=1);

namespace Egyjs\DbmlToLaravel\Parsing\Dbml;

use RuntimeException;

class JsonSnapshotStore implements SnapshotStore
{
    private const CURRENT_VERSION = 1;

    public function read(string $dbmlPath): ?Schema
    {
        if (! $this->exists($dbmlPath)) {
            return null;
        }

        $content = file_get_contents($this->snapshotPath($dbmlPath));
        $payload = json_decode($content, true, 512, JSON_THROW_ON_ERROR);

        if (($payload['version'] ?? 0) !== self::CURRENT_VERSION) {
            throw new RuntimeException(
                'Incompatible snapshot version. Delete `.dbml-sync.json` and re-run `generate:dbml`.'
            );
        }

        return SchemaFactory::fromArray($payload);
    }

    public function write(string $dbmlPath, array $jsonPayload): void
    {
        $jsonPayload['version'] = self::CURRENT_VERSION;
        $jsonPayload['generated_at'] = date('c');

        $dir = dirname($dbmlPath);
        if (! is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        file_put_contents(
            $this->snapshotPath($dbmlPath),
            json_encode($jsonPayload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)
        );
    }

    public function exists(string $dbmlPath): bool
    {
        return file_exists($this->snapshotPath($dbmlPath));
    }

    private function snapshotPath(string $dbmlPath): string
    {
        return dirname($dbmlPath).'/.dbml-sync.json';
    }
}