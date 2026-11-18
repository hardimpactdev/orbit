<?php

declare(strict_types=1);

namespace App\Spike\DurableFirewall;

use Workflow\V2\Activity;

/** SPIKE: the Durable Workflow activity around one typed primitive (see PrimitiveClient). */
final class ExecutorPrimitive extends Activity
{
    public ?string $connection = 'gateway';

    public ?string $queue = 'dw-activity';

    public int $tries = 3;

    /** @return array{status: string, stdout: string, repeat: bool, accepted_ms: ?int, result_ms: int} */
    public function handle(string $workflowId, string $step, int $nodeId, string $action, array $input = []): array
    {
        return PrimitiveClient::call("{$workflowId}:{$step}", $step, $nodeId, $action, $input);
    }
}
