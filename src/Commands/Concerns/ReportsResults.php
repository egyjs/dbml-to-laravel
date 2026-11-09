<?php

namespace Egyjs\DbmlToLaravel\Commands\Concerns;

use Illuminate\Filesystem\Filesystem;
use Symfony\Component\Console\Input\ArgvInput;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Shared --dry-run / --json handling and a machine-readable run report,
 * so scripts and AI agents can preview changes and parse results.
 */
trait ReportsResults
{
    /**
     * @var array{written: list<string>, skipped: list<array{target: string, reason: string}>, errors: list<string>}
     */
    protected array $report = ['written' => [], 'skipped' => [], 'errors' => []];

    protected function startReport(): void
    {
        $this->report = ['written' => [], 'skipped' => [], 'errors' => []];

        if ($this->wantsJson()) {
            // Silence human-readable output; the JSON report is written at the end.
            $this->output->setVerbosity(OutputInterface::VERBOSITY_QUIET);
        }
    }

    /**
     * Whether a confirmation prompt can actually be answered.
     *
     * Symfony only detects a missing TTY via posix_isatty(), which does not
     * exist on Windows, so piped/agent shells there still look interactive
     * and confirm() would block forever.
     */
    protected function canPrompt(): bool
    {
        if (! $this->input->isInteractive()) {
            return false;
        }

        if ($this->input instanceof ArgvInput
            && defined('STDIN')
            && function_exists('stream_isatty')
            && ! @stream_isatty(STDIN)) {
            return false;
        }

        return true;
    }

    protected function isDryRun(): bool
    {
        return (bool) $this->option('dry-run');
    }

    protected function wantsJson(): bool
    {
        return (bool) $this->option('json');
    }

    /**
     * Write a generated file, or only record it when --dry-run is set.
     */
    protected function writeGeneratedFile(string $path, string $content): void
    {
        if (! $this->isDryRun()) {
            $directory = dirname($path);

            if (! is_dir($directory)) {
                mkdir($directory, 0755, true);
            }

            (new Filesystem)->put($path, $content);
        }

        $this->report['written'][] = $this->relativePath($path);
    }

    protected function reportSkipped(string $target, string $reason): void
    {
        $this->report['skipped'][] = ['target' => $target, 'reason' => $reason];
    }

    protected function reportError(string $message): void
    {
        $this->report['errors'][] = $message;
        $this->error($message);
    }

    /**
     * Emit the JSON report (when requested) and return the exit code:
     * FAILURE when the given status is FAILURE or any error was reported.
     */
    protected function finish(int $status = self::SUCCESS): int
    {
        if ($status === self::SUCCESS && $this->report['errors'] !== []) {
            $status = self::FAILURE;
        }

        if ($this->wantsJson()) {
            $this->output->writeln((string) json_encode([
                'success' => $status === self::SUCCESS,
                'dry_run' => $this->isDryRun(),
                'written' => $this->report['written'],
                'skipped' => $this->report['skipped'],
                'errors' => $this->report['errors'],
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), OutputInterface::VERBOSITY_QUIET);
        }

        return $status;
    }

    /**
     * Report an error and return FAILURE (used for early exits).
     */
    protected function failWith(string $message): int
    {
        $this->reportError($message);

        return $this->finish(self::FAILURE);
    }

    private function relativePath(string $path): string
    {
        $base = rtrim(str_replace('\\', '/', base_path()), '/').'/';
        $normalized = str_replace('\\', '/', $path);

        return str_starts_with($normalized, $base) ? substr($normalized, strlen($base)) : $normalized;
    }
}
