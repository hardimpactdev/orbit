<?php

declare(strict_types=1);

namespace App\Infrastructure\Metrics;

use App\Domain\Metrics\MetricsRuntimeLifecycle;
use App\Domain\Shared\ResourceOperationException;
use App\Infrastructure\AgentCommands\AgentCommandRefused;
use App\Infrastructure\AgentCommands\AgentCommandRunner;
use App\Infrastructure\Processes\CommandDeadline;
use App\Models\Node;

final readonly class MetricsRuntimeCommands implements MetricsRuntimeLifecycle
{
    public function __construct(private AgentCommandRunner $agents, private CommandDeadline $deadline) {}

    public function health(Node $node, string $service): bool
    {
        try {
            return $this->run($node, 'metrics.runtime-health', ['service' => $service], 'healthy');
        } catch (\Throwable) {
            return false;
        }
    }

    /** @param array<string, mixed> $input */
    private function run(Node $node, string $command, array $input, string $key): bool
    {
        try {
            $result = $this->agents->run($node, $command, $input, $this->deadline->cap(PHP_FLOAT_MAX));
        } catch (AgentCommandRefused $exception) {
            $status = $exception->details['status'] ?? 502;
            throw new ResourceOperationException($exception->errorCode, $exception->getMessage(), in_array($status, [409, 422, 502], true) ? $status : 502, $exception);
        }
        if (! is_bool($result[$key] ?? null)) {
            throw new ResourceOperationException('metrics.runtime_result_invalid', 'The Metrics command returned an invalid result.', 502);
        }

        return $result[$key];
    }
}
