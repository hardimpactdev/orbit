<?php

declare(strict_types=1);

namespace App\Actions\Tasks;

use App\Data\Tasks\TaskSwampRunData;
use App\Data\Tasks\TaskSwampRunsData;
use App\Data\Tasks\TaskSwampStepData;
use App\Domain\Tasks\SwampRunReader;
use App\Infrastructure\Activity\CommandActivityInputSanitizer;
use App\Models\Task;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Config;
use RuntimeException;
use Throwable;

final readonly class ReadTaskSwampRunsAction
{
    public function __construct(
        private SwampRunReader $reader,
        private CommandActivityInputSanitizer $sanitizer,
    ) {}

    public function execute(Task $group): TaskSwampRunsData
    {
        abort_unless($group->parent_id === null, 404);
        $key = 'task-swamp:'.$group->id.':'.hash('sha256', Config::string('swamp.address').Config::string('swamp.read_token'));
        $cached = Cache::get($key);
        $previous = is_array($cached) ? TaskSwampRunsData::from($cached) : null;
        if ($previous !== null && $previous->observed_at !== null && CarbonImmutable::parse($previous->observed_at)->greaterThan(now()->subSeconds(5))) {
            return $previous;
        }
        try {
            $snapshot = $this->reader->read($group->id);
            $runs = array_map($this->run(...), array_slice($snapshot['runs'], 0, 5));
            $result = new TaskSwampRunsData('available', now()->toIso8601String(), $runs, $snapshot['truncated'] || count($snapshot['runs']) > 5);
            Cache::put($key, $result->toArray(), 86_400);

            return $result;
        } catch (Throwable) {
            if ($previous === null) {
                return new TaskSwampRunsData('unavailable', null, [], false);
            }

            return new TaskSwampRunsData('stale', $previous->observed_at, $previous->runs, $previous->truncated);
        }
    }

    /** @param array<string, mixed> $raw */
    private function run(array $raw): TaskSwampRunData
    {
        $run = $this->sanitizer->sanitizeProperties($this->removeToken($raw));
        $steps = [];
        $totalSteps = 0;
        $budget = 32_768;
        if (! is_array($run['jobs'] ?? null)) {
            throw new RuntimeException('Missing Swamp jobs.');
        }
        foreach ($run['jobs'] as $job) {
            if (! is_array($job) || ! is_array($job['steps'] ?? null)) {
                throw new RuntimeException('Invalid Swamp job.');
            }
            foreach ($job['steps'] as $step) {
                if (! is_array($step)) {
                    throw new RuntimeException('Invalid Swamp step.');
                }
                $totalSteps++;
                if (count($steps) >= 100) {
                    continue;
                }
                $text = '';
                if (isset($step['error'])) {
                    $text = $this->string($step['error'])."\n";
                }
                if (isset($step['outputs'])) {
                    $text .= json_encode($step['outputs'], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
                }
                $text = $this->plain($text);
                $output = $this->tail($text, min(8_192, $budget));
                $budget -= strlen($output);
                $steps[] = new TaskSwampStepData(
                    $this->label($job['name'] ?? null), $this->label($step['name'] ?? null), $this->label($step['status'] ?? null),
                    $this->date($step['startedAt'] ?? null), $this->date($step['completedAt'] ?? null), $output, strlen($output) < strlen($text),
                );
            }
        }
        $log = $this->plain($this->string($run['log'] ?? ''));
        $tail = $this->tail($log, 16_384);

        return new TaskSwampRunData(
            $this->label($run['id'] ?? null), $this->label($run['workflowName'] ?? null), $this->label($run['status'] ?? null),
            $this->date($run['startedAt'] ?? null), $this->date($run['completedAt'] ?? null), $steps, $tail,
            ($run['log_truncated'] ?? false) === true || strlen($tail) < strlen($log), $totalSteps > count($steps),
        );
    }

    /** @param array<array-key, mixed> $values
     * @return array<array-key, mixed>
     */
    private function removeToken(array $values): array
    {
        $token = Config::string('swamp.read_token');
        foreach ($values as $key => $value) {
            $values[$key] = is_array($value) ? $this->removeToken($value) : (is_string($value) && $token !== '' ? str_replace($token, '[REDACTED]', $value) : $value);
        }

        return $values;
    }

    private function string(mixed $value): string
    {
        if (! is_string($value)) {
            throw new RuntimeException('Invalid Swamp text.');
        }

        return $value;
    }

    private function label(mixed $value): string
    {
        return mb_strcut($this->plain($this->string($value)), 0, 256, 'UTF-8');
    }

    private function date(mixed $value): ?string
    {
        return is_string($value) && preg_match('/^\d{4}-\d{2}-\d{2}T[0-9:.+Z-]+$/D', $value) === 1 ? mb_strcut($value, 0, 40, 'UTF-8') : null;
    }

    private function plain(string $value): string
    {
        $value = preg_replace('/\x1b\][^\x07\x1b]*(?:\x07|\x1b\\\\)|\x1b\[[0-?]*[ -\/]*[@-~]/', '', $value) ?? '';

        return preg_replace('/[\x00-\x08\x0b-\x1f\x7f]/', '', $value) ?? '';
    }

    private function tail(string $value, int $bytes): string
    {
        if ($bytes === 0) {
            return '';
        }

        return strlen($value) <= $bytes ? $value : mb_strcut($value, strlen($value) - $bytes, $bytes, 'UTF-8');
    }
}
