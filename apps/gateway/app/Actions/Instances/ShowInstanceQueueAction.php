<?php

declare(strict_types=1);

namespace App\Actions\Instances;

use App\Domain\Instances\Queue\InstanceQueueReader;
use App\Domain\Instances\Queue\InstanceQueueTargets;
use App\Models\Instance;
use Carbon\CarbonImmutable;
use Carbon\Exceptions\InvalidFormatException;

final readonly class ShowInstanceQueueAction
{
    public function __construct(private InstanceQueueTargets $targets, private InstanceQueueReader $queues) {}

    /**
     * @param  'pending'|'failed'  $state
     * @return array<string, mixed>
     */
    public function execute(Instance $instance, string $state, int $limit): array
    {
        $target = $this->targets->for($instance);
        if ($target === null) {
            return ['available' => false, 'state' => $state];
        }

        $report = $this->queues->read($target, $state, $limit);

        // The values came out of the application's data. Every one is cast and bounded again here, and only these
        // fields leave the Gateway, so a job payload can never pass through.
        return [
            'available' => true,
            'state' => $state,
            'totals' => [
                'pending' => $this->count($report['pending']),
                'failed' => $report['failed'] === null ? null : $this->count($report['failed']),
            ],
            'queues' => $this->rows($report['queues'], 50, fn (array $queue): array => [
                'name' => $this->text($queue['name'], 100),
                'length' => $this->count($queue['length']),
            ]),
            'jobs' => $this->rows($report['jobs'], $limit, fn (array $job): array => [
                'id' => $this->text($job['id'], 100),
                'name' => $this->text($job['name'], 200),
                'queue' => $this->text($job['queue'], 100),
                'pushed_at' => $this->time($job['pushed_at']),
                'failed_at' => $this->time($job['failed_at']),
                'exception' => $this->text($job['exception'], 300) ?: null,
            ]),
        ];
    }

    /**
     * @param  callable(array<array-key, mixed>): array<string, mixed>  $map
     * @return list<array<string, mixed>>
     */
    private function rows(mixed $rows, int $limit, callable $map): array
    {
        return array_values(array_map(
            $map,
            array_slice(array_filter(is_array($rows) ? $rows : [], is_array(...)), 0, $limit),
        ));
    }

    private function text(mixed $value, int $length): string
    {
        return is_scalar($value)
            ? mb_substr((string) preg_replace('/[\x00-\x1F\x7F]/u', ' ', (string) $value), 0, $length)
            : '';
    }

    private function count(mixed $value): int
    {
        return is_numeric($value) ? max(0, (int) $value) : 0;
    }

    /** A push time as seconds since the epoch, or a failure time as the database prints it. */
    private function time(mixed $value): ?string
    {
        try {
            $time = match (true) {
                is_numeric($value) => CarbonImmutable::createFromTimestampUTC((int) $value),
                is_string($value) && $value !== '' => CarbonImmutable::parse($value),
                default => null,
            };
        } catch (InvalidFormatException) {
            return null;
        }

        return $time === null || $time->getTimestamp() <= 0 ? null : $time->utc()->format('Y-m-d\TH:i:s\Z');
    }
}
