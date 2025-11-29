<?php

declare(strict_types=1);

namespace Egyjs\DbmlToLaravel\Parsing\Dbml;

class SchemaFactory
{
    public static function fromArray(array $payload): Schema
    {
        $tables = [];
        $columnIndex = [];

        foreach ($payload['tables'] ?? [] as $tableData) {
            $schemaName = (string) ($tableData['schema'] ?? 'public');
            $columns = [];

            foreach ($tableData['columns'] ?? [] as $columnData) {
                $typeData = (array) ($columnData['type'] ?? []);
                $type = new ColumnType(
                    (string) ($typeData['name'] ?? ''),
                    $typeData['schemaName'] ?? null,
                    (array) ($typeData['args'] ?? [])
                );

                $column = new Column(
                    (string) $columnData['name'],
                    $type,
                    (bool) ($columnData['primaryKey'] ?? false),
                    (bool) ($columnData['unique'] ?? false),
                    (bool) ($columnData['notNull'] ?? false),
                    (bool) ($columnData['autoIncrement'] ?? false),
                    ColumnDefaultValue::fromArray($columnData['defaultValue'] ?? null)
                );

                $columns[] = $column;
                $columnIndex[self::columnKey($schemaName, (string) $tableData['name'], (string) $columnData['name'])] = $column;
            }

            $indexes = array_map(
                fn (array $indexData) => new IndexDefinition(
                    $indexData['name'] ?? null,
                    self::normalizeIndexColumns($indexData['columns'] ?? []),
                    (bool) ($indexData['unique'] ?? false),
                    $indexData['type'] ?? null
                ),
                array_filter(
                    $tableData['indexes'] ?? [],
                    fn (array $indexData) => ! empty($indexData['columns'])
                )
            );

            $tables[] = new Table((string) $tableData['name'], $schemaName, $columns, $indexes);
        }

        self::attachReferences($payload['refs'] ?? [], $columnIndex);

        $enums = [];
        foreach ($payload['enums'] ?? [] as $enumData) {
            $enumValues = array_map('strval', $enumData['values'] ?? []);
            $values = array_map(
                fn (string $value) => new EnumValue($value),
                $enumValues
            );

            $enums[$enumData['name']] = new EnumDefinition((string) $enumData['name'], $values);
        }

        return new Schema($tables, $enums);
    }

    /**
     * @param  array<string, Column>  $columnIndex
     */
    private static function attachReferences(array $refs, array $columnIndex): void
    {
        foreach ($refs as $ref) {
            $endpoints = $ref['endpoints'] ?? [];
            if (count($endpoints) !== 2) {
                continue;
            }

            $direction = self::determineDirection($endpoints[0], $endpoints[1]);
            if ($direction === null) {
                continue;
            }

            [$referencing, $referenced] = $direction;
            $referenceTable = new ReferenceTable(
                (string) $referenced['table'],
                $referenced['schema'] ?? null
            );

            $referencedColumn = (string) ($referenced['columns'][0] ?? 'id');
            $onDelete = isset($ref['onDelete']) ? strtolower((string) $ref['onDelete']) : null;
            $onUpdate = isset($ref['onUpdate']) ? strtolower((string) $ref['onUpdate']) : null;

            foreach ($referencing['columns'] ?? [] as $columnName) {
                $key = self::columnKey(
                    (string) ($referencing['schema'] ?? 'public'),
                    (string) $referencing['table'],
                    (string) $columnName
                );

                if (! isset($columnIndex[$key])) {
                    continue;
                }

                $columnIndex[$key]->addReference(new ColumnReference($referenceTable, $referencedColumn, $onDelete, $onUpdate));
            }
        }
    }

    private static function normalizeIndexColumns(array $columns): array
    {
        return array_values(array_filter(array_map(
            fn ($column) => is_array($column)
                ? (string) ($column['name'] ?? $column['value'] ?? '')
                : (string) $column,
            $columns
        ), fn (string $column) => $column !== ''));
    }

    private static function columnKey(string $schema, string $table, string $column): string
    {
        return strtolower($schema.'.'.$table.'.'.$column);
    }

    private static function determineDirection(array $first, array $second): ?array
    {
        $firstRelation = $first['relation'] ?? null;
        $secondRelation = $second['relation'] ?? null;

        if ($firstRelation === '*' && $secondRelation === '1') {
            return [$first, $second];
        }

        if ($firstRelation === '1' && $secondRelation === '*') {
            return [$second, $first];
        }

        return null;
    }
}
