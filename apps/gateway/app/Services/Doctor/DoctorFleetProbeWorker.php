<?php

declare(strict_types=1);

namespace App\Services\Doctor;

use App\Models\Node;
use Illuminate\Contracts\Process\InvokedProcess;
use Illuminate\Support\Facades\Process as ProcessFacade;
use JsonException;
use LogicException;
use Throwable;

/**
 * Owns the fleet Doctor child-process protocol and its issue trust boundary.
 *
 * @mago-expect lint:cyclomatic-complexity
 * @mago-expect lint:kan-defect
 */
final readonly class DoctorFleetProbeWorker
{
    public function __construct(
        private DoctorIssueFactory $doctorIssueFactory,
    ) {}

    public function canRun(): bool
    {
        if (! function_exists('proc_open')) {
            return false;
        }

        $defaultConnection = (string) config('database.default');
        $database = config("database.connections.{$defaultConnection}.database");

        return is_string($database) && $database !== '' && $database !== ':memory:';
    }

    /**
     * @param  list<string>  $families
     */
    public function start(Node $node, array $families, ?string $key): ?InvokedProcess
    {
        $command = [
            'php',
            'artisan',
            'orbit:internal:doctor-fleet-probe-node',
            '--node='.$node->name,
            '--no-ansi',
        ];

        foreach ($families as $family) {
            $command[] = '--families='.$family;
        }

        if ($key !== null) {
            $command[] = '--key='.$key;
        }

        try {
            return ProcessFacade::path(base_path())
                ->timeout(600)
                ->env($this->environment())
                ->start($command);
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * @return array<string, mixed>|null
     */
    public function decodeReport(string $output): ?array
    {
        $lines = array_values(array_filter(array_map(trim(...), explode("\n", $output))));

        foreach (array_reverse($lines) as $line) {
            try {
                /** @var array<string, mixed> $payload */
                $payload = json_decode(
                    $line,
                    associative: true,
                    depth: 512,
                    flags: JSON_THROW_ON_ERROR,
                );
            } catch (JsonException) {
                continue;
            }

            $report = $payload['report'] ?? null;

            if (! is_array($report)) {
                continue;
            }

            /** @var array<string, mixed> $report */
            return $report;
        }

        return null;
    }

    /**
     * Rebuild subprocess issues from observation fields before the parent trusts them.
     *
     * @param  array<string, mixed>  $report
     * @return array<string, mixed>
     */
    public function canonicalizeReport(array $report): array
    {
        $values = $report['issues'] ?? [];

        if (! is_array($values)) {
            throw new LogicException('Fleet Doctor probe issues must be an array.');
        }

        $issues = [];

        foreach ($values as $value) {
            if (! is_array($value)) {
                throw new LogicException('Fleet Doctor probe issues must use the Doctor issue contract.');
            }

            /** @var array<string, mixed> $value */
            $issues[] = $this->doctorIssueFactory->fromClientArray($value)->toArray();
        }

        $report['issues'] = $issues;

        return $report;
    }

    /**
     * @param  callable(string, 'running'|'done', list<array<string, mixed>>, ?int, ?int): void  $onFamilyProgress
     */
    public function drainProgress(
        InvokedProcess $process,
        string &$outputBuffer,
        callable $onFamilyProgress,
    ): void {
        $chunk = $process->latestOutput();

        if ($chunk === '') {
            return;
        }

        $outputBuffer .= $chunk;

        while (($newlinePosition = strpos($outputBuffer, needle: "\n")) !== false) {
            $line = substr($outputBuffer, offset: 0, length: $newlinePosition);
            $outputBuffer = substr($outputBuffer, offset: $newlinePosition + 1);

            $this->applyProgressLine($line, $onFamilyProgress);
        }
    }

    /**
     * @return array<string, string>
     */
    private function environment(): array
    {
        $defaultConnection = (string) config('database.default');
        $environment = [
            'APP_ENV' => (string) config('app.env'),
            'DB_CONNECTION' => $defaultConnection,
        ];

        $database = config("database.connections.{$defaultConnection}.database");

        if (is_string($database) && $database !== '') {
            $environment['DB_DATABASE'] = $database;
        }

        $appKey = config('app.key');

        if (is_string($appKey) && $appKey !== '') {
            $environment['APP_KEY'] = $appKey;
        }

        return $environment;
    }

    /**
     * @param  callable(string, 'running'|'done', list<array<string, mixed>>, ?int, ?int): void  $onFamilyProgress
     */
    private function applyProgressLine(string $line, callable $onFamilyProgress): void
    {
        $progress = $this->decodeProgressLine($line);

        if ($progress === null) {
            return;
        }

        $family = is_string($progress['family'] ?? null) ? $progress['family'] : '';
        $phase = is_string($progress['phase'] ?? null) ? $progress['phase'] : '';

        if ($family === '' || $phase !== 'running' && $phase !== 'done') {
            return;
        }

        $completedValue = $progress['completed'] ?? null;
        $totalValue = $progress['total'] ?? null;

        $completed = is_int($completedValue) ? $completedValue : null;
        $total = is_int($totalValue) ? $totalValue : null;

        $onFamilyProgress($family, $phase, [], $completed, $total);
    }

    /**
     * @return array<array-key, mixed>|null
     */
    private function decodeProgressLine(string $line): ?array
    {
        $line = trim($line);

        if ($line === '') {
            return null;
        }

        try {
            /** @var array<string, mixed> $payload */
            $payload = json_decode($line, associative: true, depth: 512, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return null;
        }

        if (! array_key_exists('progress', $payload) || ! is_array($payload['progress'])) {
            return null;
        }

        return $payload['progress'];
    }
}
