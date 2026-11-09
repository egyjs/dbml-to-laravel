<?php

declare(strict_types=1);

use Egyjs\DbmlToLaravel\Parsing\NodeDbmlParser;
use Illuminate\Support\Facades\Artisan;

/**
 * Docs that AI tools read (README, llms.txt, Boost files, context7.json)
 * must stay valid: broken examples get copied verbatim by agents.
 */
function packageRoot(): string
{
    return dirname(__DIR__, 2);
}

/**
 * @return list<string>
 */
function dbmlBlocks(string $file): array
{
    preg_match_all('/```dbml\R(.*?)```/s', (string) file_get_contents(packageRoot().'/'.$file), $matches);

    return $matches[1];
}

it('has parseable DBML examples in agent-facing docs', function (string $file) {
    $blocks = dbmlBlocks($file);

    expect($blocks)->not->toBeEmpty();

    foreach ($blocks as $block) {
        $path = tempnam(sys_get_temp_dir(), 'dbml');
        file_put_contents($path, $block);

        try {
            $schema = (new NodeDbmlParser)->parse($path);
            expect($schema->getTables())->not->toBeEmpty();
        } finally {
            unlink($path);
        }
    }
})->with(['README.md', 'llms.txt']);

it('ships Laravel Boost guidelines and skill at the paths Boost discovers', function () {
    $guideline = packageRoot().'/resources/boost/guidelines/core.blade.php';
    $skill = packageRoot().'/resources/boost/skills/dbml-to-laravel/SKILL.md';

    expect($guideline)->toBeFile()
        ->and($skill)->toBeFile()
        ->and(str_replace("\r\n", "\n", (string) file_get_contents($skill)))->toStartWith("---\nname: dbml-to-laravel\ndescription: ");
});

it('documents only commands, options and markers that exist', function (string $file) {
    $content = (string) file_get_contents(packageRoot().'/'.$file);
    $stub = (string) file_get_contents(packageRoot().'/stubs/model.stub');

    preg_match_all('/@dbml-sync:([a-z-]+)/', $content, $markers);
    foreach (array_unique($markers[1]) as $marker) {
        expect($stub)->toContain("@dbml-sync:$marker");
    }

    preg_match_all('/artisan (dbml:[a-z]+|generate:dbml)/', $content, $commands);
    foreach (array_unique($commands[1]) as $command) {
        expect(array_keys(Artisan::all()))->toContain($command);
    }

    // Every documented command option must exist on the package commands.
    $options = [];
    foreach (['dbml:generate', 'dbml:sync'] as $command) {
        $options = array_merge($options, array_keys(Artisan::all()[$command]->getDefinition()->getOptions()));
    }

    preg_match_all('/artisan (?:dbml:[a-z]+|generate:dbml)[^\n`]*/', $content, $lines);
    foreach ($lines[0] as $line) {
        preg_match_all('/--([a-z-]+)/', $line, $flags);
        foreach ($flags[1] as $flag) {
            expect($options)->toContain($flag);
        }
    }
})->with([
    'README.md',
    'llms.txt',
    'resources/boost/guidelines/core.blade.php',
    'resources/boost/skills/dbml-to-laravel/SKILL.md',
]);

it('has a valid context7.json', function () {
    $config = json_decode((string) file_get_contents(packageRoot().'/context7.json'), true, 512, JSON_THROW_ON_ERROR);

    expect(strlen($config['projectTitle']))->toBeLessThanOrEqual(100)
        ->and(strlen($config['description']))->toBeLessThanOrEqual(500)
        ->and($config['rules'])->not->toBeEmpty();

    foreach ($config['folders'] as $folder) {
        expect(packageRoot().'/'.$folder)->toBeDirectory();
    }
});
