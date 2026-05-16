<?php

declare(strict_types=1);

namespace Egyjs\DbmlToLaravel\Generation;

use Egyjs\DbmlToLaravel\Parsing\Dbml\Schema;
use Egyjs\DbmlToLaravel\Parsing\Dbml\Table;

class ModelPatcher
{
    private const SECTIONS = ['table-property', 'fillable', 'casts', 'relations'];

    public function __construct(
        private ModelContentBuilder $modelBuilder
    ) {}

    public function patch(string $modelContent, Table $table, string $modelName, Schema $schema): string
    {
        $sections = $this->generateSections($table, $modelName, $schema);

        foreach (self::SECTIONS as $section) {
            $marker = "@dbml-sync:{$section}";
            $endMarker = "@enddbml-sync:{$section}";

            if ($sections[$section] === null) {
                continue;
            }

            $content = $sections[$section];

            if ($this->hasMarkerPair($modelContent, $marker, $endMarker)) {
                $modelContent = $this->replaceBetweenMarkers($modelContent, $marker, $endMarker, $content);
            } else {
                $modelContent = $this->insertMarkerBlock($modelContent, $section, $content);
            }
        }

        return $modelContent;
    }

    public function hasMarkerPair(string $content, string $marker, string $endMarker): bool
    {
        return str_contains($content, "// {$marker}") && str_contains($content, "// {$endMarker}");
    }

    private function replaceBetweenMarkers(string $content, string $marker, string $endMarker, string $newContent): string
    {
        $pattern = '/\/\/ ' . preg_quote($marker, '/') . '\r?\n(.*?)\/\/ ' . preg_quote($endMarker, '/') . '/s';

        return preg_replace($pattern, "// {$marker}\n{$newContent}// {$endMarker}", $content);
    }

    private function insertMarkerBlock(string $content, string $section, string $blockContent): string
    {
        $marker = "// @dbml-sync:{$section}";
        $endMarker = "// @enddbml-sync:{$section}";
        $block = "\n    {$marker}\n{$blockContent}    {$endMarker}\n";

        // Insert before the closing } of the class
        return preg_replace('/(\n)(\s*\}\s*$)/m', $block . '$1$2', $content);
    }

    /**
     * @return array<string, string|null>
     */
    private function generateSections(Table $table, string $modelName, Schema $schema): array
    {
        $columns = $table->getColumns();
        $fillable = $this->modelBuilder->generateFillable($columns);
        $casts = $this->modelBuilder->generateCasts($columns);
        $relations = $this->modelBuilder->parseRelations(array_merge(
            $this->modelBuilder->generateBelongsToRelations($columns),
            $this->modelBuilder->generateHasManyRelations($table, $schema)
        ));
        $tableProperty = $this->modelBuilder->generateTableProperty($table->getName(), $modelName);

        $tab = str_repeat("\t", 2);

        $fillableString = '';
        if (! empty($fillable)) {
            $fillableString = "    protected \$fillable = [\n{$tab}"
                . implode(",\n{$tab}", $fillable)
                . ",\n    ];\n";
        }

        $castsString = '';
        if (! empty($casts)) {
            $castsEntries = implode(",\n{$tab}", array_map(
                fn ($key, $value) => "'$key' => '$value'",
                array_keys($casts),
                $casts
            ));
            $castsString = "    protected \$casts = [\n{$tab}{$castsEntries},\n    ];\n";
        }

        $relationString = '';
        if (! empty($relations)) {
            $relationString = $relations . "\n";
        }

        return [
            'table-property' => $tableProperty !== '' ? "    {$tableProperty}\n" : null,
            'fillable' => $fillableString !== '' ? $fillableString : null,
            'casts' => $castsString !== '' ? $castsString : null,
            'relations' => $relationString !== '' ? $relationString : null,
        ];
    }
}