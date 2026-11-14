<?php

declare(strict_types=1);

namespace App\Commands\Conn;

use App\Commands\GatewayCommand;
use App\Support\Operations\OperationFollower;
use Orbit\Sdk\GatewayApiException;
use Orbit\Sdk\GatewayConnector;
use Orbit\Sdk\Requests\Operations\ShowOperationRequest;
use Orbit\Sdk\Responses\Conn\ConnEnvironmentRestartResponse;
use Orbit\Sdk\Responses\Conn\ConnEnvironmentRestartsResponse;
use Orbit\Sdk\Responses\Operations\OperationResponse;

/**
 * The shared output of `conn:environment-restart` and `conn:node-environments-restart` (docs/cli/conn/environment-restart.mdx): one
 * line per Instance. A `restarting` answer is followed to its Operation's end and printed as `restarted`, `skipped` or `failed`.
 *
 * @phpstan-require-extends GatewayCommand
 */
trait RestartsConnEnvironments
{
    public function extensionSlug(): string
    {
        return 'conn';
    }

    protected function writeRestarts(GatewayConnector $connector, ConnEnvironmentRestartsResponse $response): int
    {
        $lines = [];
        try {
            foreach ($response->restarts as $restart) {
                $lines[] = $this->restartLine($connector, $restart);
            }
        } catch (GatewayApiException $exception) {
            return $this->renderApiFailure($exception);
        }
        if ($this->option('json') === true) {
            $this->writeJson(['restarts' => $lines]);
        } elseif ($lines === []) {
            $this->writeHumanMessage('No container Instance.');
        }

        return in_array('failed', array_column($lines, 'answer'), true) ? self::FAILURE : self::SUCCESS;
    }

    /** @return array{instance_id: int, instance: string, answer: string, reason: string|null, operation_id: int|null} */
    private function restartLine(GatewayConnector $connector, ConnEnvironmentRestartResponse $restart): array
    {
        [$answer, $reason] = [$restart->answer, $restart->reason];
        if ($restart->answer === 'restarting' && $restart->operationId !== null) {
            if ($this->option('json') !== true) {
                $this->writeHumanMessage("{$restart->instanceId} {$restart->instance}: restarting T3 (Operation {$restart->operationId})");
            }
            $operation = $this->restartEnd($connector, $restart->operationId);
            $result = $operation->result ?? [];
            [$answer, $reason] = $operation->succeeded() && in_array($result['answer'] ?? null, ['restarted', 'skipped', 'busy'], true)
                ? [(string) $result['answer'], is_string($result['reason'] ?? null) ? $result['reason'] : null]
                : ['failed', $operation->errorCode ?? $operation->status];
            // The run's last guard names what T3's service runs: a restart would end it.
            if ($reason === 'processes' && is_int($result['terminals'] ?? null) && is_int($result['commands'] ?? null)) {
                $reason = "processes: {$result['terminals']} terminal(s), {$result['commands']} command(s)";
            }
        }
        if ($this->option('json') !== true) {
            $this->writeHumanMessage("{$restart->instanceId} {$restart->instance}: {$answer}".($reason === null ? '' : " ({$reason})"));
        }

        return ['instance_id' => $restart->instanceId, 'instance' => $restart->instance, 'answer' => $answer, 'reason' => $reason,
            'operation_id' => $restart->operationId];
    }

    /** Each restart ends within its run budget; the Operation never retries, so the follow reads until it is terminal. */
    private function restartEnd(GatewayConnector $connector, int $operationId): OperationResponse
    {
        $read = fn (): OperationResponse => $this->sendOrThrow($connector, new ShowOperationRequest($operationId), OperationResponse::class);
        $operation = $read();
        $follower = new OperationFollower($read, null);
        try {
            while (! $operation->terminal()) {
                $operation = $follower->next($operation);
            }
        } finally {
            $follower->close();
        }

        return $operation;
    }
}
