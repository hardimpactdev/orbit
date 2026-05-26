<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Concerns\WithSpinner;
use App\Concerns\WithStepTree;
use App\Http\Gateway\GatewayApiException;
use App\Http\Gateway\GatewayConnector;
use App\Http\Gateway\Requests\Nodes\AddNodeRoleRequest;
use App\Http\Gateway\Responses\Nodes\NodeRoleMutationResponse;
use App\Models\Node;
use App\Services\Nodes\Roles\NodeRoleAssignmentPayload;
use App\Services\Nodes\Roles\NodeRoleAssignmentService;
use Illuminate\Console\Command;
use InvalidArgumentException;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputOption;
use Throwable;

class NodeRoleAddCommand extends Command
{
    use WithSpinner;
    use WithStepTree;

    #[\Override]
    protected $name = 'node role:add';

    #[\Override]
    protected $description = 'Add a hosted role to a node';

    protected function configure(): void
    {
        parent::configure();

        $this->addArgument('node', InputArgument::REQUIRED, 'Name of the node');
        $this->addArgument('role', InputArgument::REQUIRED, 'Role to add');
        $this->addOption('tld', null, InputOption::VALUE_REQUIRED, 'Development TLD for app-development');
        $this->addOption('json', null, InputOption::VALUE_NONE, 'Output as JSON');
    }

    public function handle(NodeRoleAssignmentService $service): int
    {
        $nodeName = (string) $this->argument('node');
        $role = (string) $this->argument('role');

        $validation = $this->validateRoleInput($role);
        if ($validation !== null) {
            return $this->failCommand($validation['code'], $validation['message'], $validation['meta']);
        }

        $settings = $this->settingsForRole($role);
        if (is_int($settings)) {
            return $settings;
        }

        if ((bool) config('orbit.is_gateway', false)) {
            $node = Node::query()->where('name', $nodeName)->where('status', 'active')->first();

            if (! $node instanceof Node) {
                return $this->failCommand('node.not_found', "Node '{$nodeName}' not found.", ['name' => $nodeName]);
            }

            try {
                $assignment = null;
                $operation = function () use ($service, $node, $role, $settings, &$assignment): string {
                    $assignment = $service->add($node, $role, $settings);

                    return 'converged';
                };

                if (! $this->wantsJson()) {
                    $exitCode = $this->runRoleMutationTree('Add Node Role', $nodeName, $role, $operation, 'Role added');
                    if ($exitCode !== self::SUCCESS || $assignment === null) {
                        return self::FAILURE;
                    }
                } else {
                    $operation();
                }

                return $this->respondMutation($nodeName, NodeRoleAssignmentPayload::fromModel($assignment), 'added');
            } catch (InvalidArgumentException $exception) {
                return $this->failCommand('validation_failed', $exception->getMessage(), ['role' => $role]);
            }
        }

        try {
            $dto = null;
            $operation = function () use ($nodeName, $role, $settings, &$dto): string {
                /** @var NodeRoleMutationResponse $dto */
                $dto = app(GatewayConnector::class)
                    ->send(new AddNodeRoleRequest($nodeName, $role, $settings))
                    ->dto();

                return 'converged';
            };

            if (! $this->wantsJson()) {
                $exitCode = $this->runRoleMutationTree('Add Node Role', $nodeName, $role, $operation, 'Role added');
                if ($exitCode !== self::SUCCESS || ! $dto instanceof NodeRoleMutationResponse) {
                    return self::FAILURE;
                }
            } else {
                $operation();
            }

            return $this->respondMutation($dto->node, $dto->assignment, 'added');
        } catch (GatewayApiException $exception) {
            return $this->failCommand(
                $exception->errorCode() ?? 'gateway_unavailable',
                $exception->getMessage() !== '' ? $exception->getMessage() : 'Gateway connection is required to add a node role.',
                $exception->errorMeta(),
            );
        } catch (Throwable) {
            return $this->failCommand('gateway_unavailable', 'Gateway connection is required to add a node role.', []);
        }
    }

    /**
     * @return array{code: string, message: string, meta: array<string, mixed>}|null
     */
    private function validateRoleInput(string $role): ?array
    {
        if (in_array($role, ['gateway', 'vpn', 'router'], true)) {
            return [
                'code' => 'validation_failed',
                'message' => "Role '{$role}' is gateway-coupled and cannot be assigned independently.",
                'meta' => ['field' => 'role', 'role' => $role],
            ];
        }

        if ($role === 'agent') {
            return [
                'code' => 'validation_failed',
                'message' => "The agent role cannot be added through node role commands. Use 'node:new --role=agent' instead.",
                'meta' => ['field' => 'role', 'role' => $role],
            ];
        }

        return null;
    }

    /**
     * @return array<string, mixed>|int
     */
    private function settingsForRole(string $role): array|int
    {
        $tld = $this->option('tld');

        if ($role === 'app-development') {
            if (! is_string($tld) || trim($tld) === '') {
                return $this->failCommand('validation_failed', 'The app-development role requires --tld.', ['field' => 'tld']);
            }

            return ['tld' => $tld];
        }

        if (is_string($tld)) {
            return $this->failCommand('validation_failed', "Role '{$role}' does not accept --tld.", ['field' => 'tld', 'role' => $role]);
        }

        return [];
    }

    /**
     * @param  array<string, mixed>  $assignment
     */
    private function respondMutation(string $nodeName, array $assignment, string $actionWord): int
    {
        if ($this->wantsJson()) {
            $this->line(json_encode([
                'success' => [
                    'data' => [
                        'node' => $nodeName,
                        'assignment' => $assignment,
                    ],
                ],
            ], JSON_THROW_ON_ERROR));

            return self::SUCCESS;
        }

        $this->line("Role '{$assignment['role']}' {$actionWord} on '{$nodeName}'");

        return self::SUCCESS;
    }

    private function runRoleMutationTree(string $title, string $nodeName, string $role, callable $operation, string $footer): int
    {
        return $this->runStepTree($title, [
            [
                'label' => 'Validate request',
                'doneLabel' => 'Validated request',
                'run' => fn (): string => "{$nodeName} -> {$role}",
            ],
            [
                'label' => 'Apply role convergence',
                'doneLabel' => 'Applied role convergence',
                'run' => $operation,
            ],
        ], doneFooter: $footer, failFooter: "Failed to apply role '{$role}' on '{$nodeName}'");
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
