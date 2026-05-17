<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Concerns\HandlesPromptCancellation;
use App\Concerns\WithSpinner;
use App\Concerns\WithStepTree;
use App\Exceptions\PromptAborted;
use App\Http\Gateway\GatewayApiException;
use App\Http\Gateway\GatewayConnector;
use App\Http\Gateway\Requests\Nodes\RemoveNodeRoleRequest;
use App\Http\Gateway\Responses\Nodes\NodeRoleMutationResponse;
use App\Models\Node;
use App\Models\NodeRoleAssignment;
use App\Services\Nodes\Roles\NodeRoleAssignmentService;
use App\Services\Nodes\Roles\NodeRoleDependencyInspector;
use Illuminate\Console\Command;
use InvalidArgumentException;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputOption;
use Throwable;

class NodeRoleRemoveCommand extends Command
{
    use HandlesPromptCancellation;
    use WithSpinner;
    use WithStepTree;

    protected $name = 'node role:remove';

    protected $description = 'Remove a hosted role from a node';

    protected function configure(): void
    {
        parent::configure();

        $this->addArgument('node', InputArgument::REQUIRED, 'Name of the node');
        $this->addArgument('role', InputArgument::REQUIRED, 'Role to remove');
        $this->addOption('force', null, InputOption::VALUE_NONE, 'Confirm destructive dependent cleanup');
        $this->addOption('purge-data', null, InputOption::VALUE_NONE, 'Purge role-owned data');
        $this->addOption('json', null, InputOption::VALUE_NONE, 'Output as JSON');
    }

    public function handle(NodeRoleAssignmentService $service, NodeRoleDependencyInspector $dependencyInspector): int
    {
        $nodeName = (string) $this->argument('node');
        $role = (string) $this->argument('role');
        $force = (bool) $this->option('force');
        $purgeData = (bool) $this->option('purge-data');

        if ($purgeData && ! $force) {
            return $this->failCommand('validation_failed', 'The purge-data option requires --force.', ['field' => 'purge-data']);
        }

        if ($role === 'gateway') {
            return $this->failCommand('validation_failed', 'The gateway role cannot be managed through node role commands.', ['field' => 'role', 'role' => $role]);
        }

        if (! $force && ($this->wantsJson() || ! $this->input->isInteractive())) {
            return $this->failCommand('validation_failed', 'Use --force to remove this role.', ['field' => 'force']);
        }

        if (! $force && ! $this->wantsJson() && $this->input->isInteractive()) {
            try {
                $confirmed = $this->promptConfirm("Remove role '{$role}' from '{$nodeName}'?", default: false);
            } catch (PromptAborted) {
                return $this->failCommand('validation_failed', 'Operation cancelled.', []);
            }

            if (! $confirmed) {
                return $this->failCommand('validation_failed', 'Operation cancelled.', []);
            }
        }

        if ((bool) config('orbit.is_gateway', false)) {
            $node = Node::query()->where('name', $nodeName)->where('status', 'active')->first();

            if (! $node instanceof Node) {
                return $this->failCommand('node.not_found', "Node '{$nodeName}' not found.", ['name' => $nodeName]);
            }

            $assignment = NodeRoleAssignment::query()
                ->where('node_id', $node->id)
                ->where('role', $role)
                ->first();

            if (! $assignment instanceof NodeRoleAssignment) {
                return $this->failCommand('validation_failed', "Role '{$role}' is not assigned to node '{$nodeName}'.", ['role' => $role]);
            }

            $dependents = $dependencyInspector->dependentSummaries($node, $assignment);
            if ($dependents !== [] && ! $force) {
                return $this->failCommand(
                    'node_role.remove_blocked',
                    "Role '{$role}' cannot be removed while dependents exist.",
                    ['role' => $role, 'dependents' => $dependents],
                );
            }

            try {
                $operation = function () use ($service, $node, $role, $force, $purgeData): string {
                    $service->remove($node, $role, $force, $purgeData);

                    return 'removed';
                };

                if (! $this->wantsJson()) {
                    $exitCode = $this->runRoleRemovalTree($nodeName, $role, $operation);
                    if ($exitCode !== self::SUCCESS) {
                        return self::FAILURE;
                    }
                } else {
                    $operation();
                }

                return $this->respondSuccess($nodeName, $role, $purgeData);
            } catch (InvalidArgumentException $exception) {
                return $this->failCommand('validation_failed', $exception->getMessage(), ['role' => $role]);
            }
        }

        try {
            $dto = null;
            $operation = function () use ($nodeName, $role, $force, $purgeData, &$dto): string {
                /** @var NodeRoleMutationResponse $dto */
                $dto = app(GatewayConnector::class)
                    ->send(new RemoveNodeRoleRequest($nodeName, $role, $force, $purgeData))
                    ->dto();

                return 'removed';
            };

            if (! $this->wantsJson()) {
                $exitCode = $this->runRoleRemovalTree($nodeName, $role, $operation);
                if ($exitCode !== self::SUCCESS || ! $dto instanceof NodeRoleMutationResponse) {
                    return self::FAILURE;
                }
            } else {
                $operation();
            }

            return $this->respondSuccess($dto->node, $dto->removedRole ?? $role, $dto->purgedData ?? false);
        } catch (GatewayApiException $exception) {
            return $this->failCommand(
                $exception->errorCode() ?? 'gateway_unavailable',
                $exception->getMessage() !== '' ? $exception->getMessage() : 'Gateway connection is required to remove a node role.',
                $exception->errorMeta(),
            );
        } catch (Throwable) {
            return $this->failCommand('gateway_unavailable', 'Gateway connection is required to remove a node role.', []);
        }
    }

    private function respondSuccess(string $nodeName, string $role, bool $purgedData): int
    {
        if ($this->wantsJson()) {
            $this->line(json_encode([
                'success' => [
                    'data' => [
                        'node' => $nodeName,
                        'role' => $role,
                        'purged_data' => $purgedData,
                    ],
                ],
            ], JSON_THROW_ON_ERROR));

            return self::SUCCESS;
        }

        $this->line("Role '{$role}' removed from '{$nodeName}'");

        return self::SUCCESS;
    }

    private function runRoleRemovalTree(string $nodeName, string $role, callable $operation): int
    {
        return $this->runStepTree('Remove Node Role', [
            [
                'label' => 'Validate removal',
                'doneLabel' => 'Validated removal',
                'run' => fn (): string => "{$nodeName} -> {$role}",
            ],
            [
                'label' => 'Remove role convergence',
                'doneLabel' => 'Removed role convergence',
                'run' => $operation,
            ],
        ], doneFooter: 'Role removed', failFooter: "Failed to remove role '{$role}' from '{$nodeName}'");
    }

    /**
     * @param  array<string, mixed>  $meta
     */
    private function failCommand(string $code, string $message, array $meta): int
    {
        if ($this->wantsJson()) {
            $this->line(json_encode([
                'error' => [
                    'code' => $code,
                    'message' => $message,
                    'meta' => empty($meta) ? (object) [] : $meta,
                ],
            ], JSON_THROW_ON_ERROR));

            return self::FAILURE;
        }

        $this->error($message);

        return self::FAILURE;
    }

    private function wantsJson(): bool
    {
        return (bool) $this->option('json');
    }
}
