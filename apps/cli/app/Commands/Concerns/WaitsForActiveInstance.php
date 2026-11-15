<?php

declare(strict_types=1);

namespace App\Commands\Concerns;

use App\Commands\GatewayCommand;
use App\Data\GatewayProfile;
use Orbit\Sdk\GatewayConnector;
use Orbit\Sdk\Requests\Instances\ShowInstanceRequest;
use Orbit\Sdk\Requests\Operations\ShowOperationRequest;
use Orbit\Sdk\Responses\Instances\InstanceResponse;
use Orbit\Sdk\Responses\Operations\OperationResponse;

/**
 * One wait for a container clone to be `active` (docs/cli/instance/create.mdx#fully-set-up): follow the `instance.create`, then the
 * completion its result names, then read the Instance. `instance:create --wait-active` and `task:start` share it.
 *
 * @phpstan-require-extends GatewayCommand
 */
trait WaitsForActiveInstance
{
    use FollowsOperations;

    /** Whether this run waits for `active` after the create. */
    abstract protected function waitsForActive(): bool;

    /** The create's result names the clone's completion; the human summary names it too. */
    protected function writeOperationResult(OperationResponse $operation): void
    {
        $completion = self::completionId($operation);
        if ($completion !== null) {
            $this->writeHumanMessage("Completion: {$completion}");
            if (! $this->waitsForActive()) {
                $this->writeHumanMessage("Active when it succeeds: orbit operation:show {$completion}");
            }
        }
    }

    /** While waiting, the JSON is the completion's when there is one: the last Operation the command follows. */
    protected function writesOperationJson(OperationResponse $operation): bool
    {
        return ! $this->waitsForActive() || $operation->kind !== 'instance.create' || self::completionId($operation) === null;
    }

    /** Follows the create, and, when this run waits, its completion; exit 0 with a wait only when the Instance is `active`. */
    protected function followCreate(GatewayConnector $connector, GatewayProfile $profile, OperationResponse $started, string $title, string $verb): int
    {
        $code = $this->followOperation($connector, $profile, $started, $title, $verb);
        $created = $this->followedOperation;
        if (! $this->waitsForActive() || $code !== self::SUCCESS || ! $created instanceof OperationResponse) {
            return $code;
        }
        $completion = self::completionId($created);
        if ($completion !== null) {
            $next = $this->send($connector, new ShowOperationRequest($completion), OperationResponse::class);
            if (! $next instanceof OperationResponse) {
                return self::FAILURE;
            }
            $code = $this->followOperation($connector, $profile, $next, 'Complete Instance', 'Instance completion');
            if ($code !== self::SUCCESS) {
                return $code;
            }
        }
        $instance = $this->send($connector, new ShowInstanceRequest($created->targetId), InstanceResponse::class);
        if (! $instance instanceof InstanceResponse) {
            return self::FAILURE;
        }
        if ($instance->status === 'active') {
            return self::SUCCESS;
        }
        $this->writeHumanMessage("Instance {$instance->id} is {$instance->status}, not active.");

        return self::FAILURE;
    }

    private static function completionId(OperationResponse $operation): ?int
    {
        $id = $operation->result['completion_operation_id'] ?? null;

        return $operation->kind === 'instance.create' && is_int($id) ? $id : null;
    }
}
