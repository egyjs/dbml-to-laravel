<?php

declare(strict_types=1);

namespace Egyjs\DbmlToLaravel\Parsing;

use Egyjs\DbmlToLaravel\Parsing\Dbml\Schema;
use Egyjs\DbmlToLaravel\Parsing\Dbml\SchemaFactory;
use RuntimeException;
use Symfony\Component\Process\Exception\ProcessFailedException;
use Symfony\Component\Process\Process;

class NodeDbmlParser
{
    private string $parserScript;

    public function __construct(?string $parserScript = null)
    {
        $this->parserScript = $parserScript ?? $this->resolveParserScript();
    }

    public function parse(string $path): Schema
    {
        if (! is_readable($path)) {
            throw new RuntimeException("Unable to read DBML file: {$path}");
        }

        if (! is_file($this->parserScript)) {
            throw new RuntimeException('DBML parser script is missing. Please reinstall the package.');
        }

        $process = new Process(['node', $this->parserScript, $path]);
        $process->setTimeout(30);

        try {
            $process->mustRun();
        } catch (ProcessFailedException $exception) {
            $error = trim($exception->getProcess()->getErrorOutput());
            $message = $error !== '' ? $error : $exception->getMessage();
            throw new RuntimeException($message, previous: $exception);
        }

        $output = $process->getOutput();

        try {
            $payload = json_decode($output, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException $exception) {
            throw new RuntimeException('Failed to decode DBML parser output.', previous: $exception);
        }

        if (! is_array($payload)) {
            throw new RuntimeException('DBML parser returned an unexpected payload.');
        }

        return SchemaFactory::fromArray($payload);
    }

    private function resolveParserScript(): string
    {
        $binDirectory = dirname(__DIR__, 2).'/bin';
        $compiled = $binDirectory.'/parse-dbml.runtime.cjs';
        $source = $binDirectory.'/parse-dbml.js';

        if (is_file($compiled)) {
            return $compiled;
        }

        if (is_file($source)) {
            return $source;
        }

        throw new RuntimeException('DBML parser script is missing. Please reinstall the package.');
    }
}
