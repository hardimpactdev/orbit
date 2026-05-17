<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Http\Gateway\GatewayApiException;
use App\Http\Gateway\GatewayConnector;
use App\Http\Gateway\Requests\Nodes\ListNodeRolesRequest;
use App\Http\Gateway\Responses\Nodes\NodeRoleListResponse;
use App\Models\Node;
use App\Services\Nodes\Roles\NodeRoleAssignmentPayload;
use Illuminate\Console\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputOption;
use Throwable;

use function Laravel\Prompts\table;

class NodeRoleListCommand extends Command
{
    protected $name = 'node role:list';

    protected $description = 'List role assignments for a node';

    protected function configure(): void
    {
        parent::configure();

        $this->addArgument('node', InputArgument::OPTIONAL, 'Name of the node');
        $this->addOption('json', null, InputOption::VALUE_NONE, 'Output as JSON');
    }

    public function handle(): int
    {
        $nodeName = $this->argument('node');

        if (! is_string($nodeName) || $nodeName === '') {
            return $this->failCommand('validation_failed', 'Node name is required.', ['field' => 'node']);
        }

        if ((bool) config('orbit.is_gateway', false)) {
            $node = Node::query()
                ->with('roleAssignments')
                ->where('name', $nodeName)
                ->where('status', 'active')
                ->first();

            if (! $node instanceof Node) {
                return $this->failCommand('node.not_found', "Node '{$nodeName}' not found.", ['name' => $nodeName]);
            }

            $roles = $node->roleAssignments
                ->map(fn ($assignment): array => NodeRoleAssignmentPayload::fromModel($assignment))
                ->all();

            return $this->respondSuccess($node->name, $roles);
        }

        try {
            /** @var NodeRoleListResponse $dto */
            $dto = app(GatewayConnector::class)
                ->send(new ListNodeRolesRequest($nodeName))
                ->dto();
        } catch (GatewayApiException $exception) {
            return $this->failCommand(
                $exception->errorCode() ?? 'gateway_unavailable',
                $exception->getMessage() !== '' ? $exception->getMessage() : 'Gateway connection is required to list node roles.',
                $exception->errorMeta(),
            );
        } catch (Throwable) {
            return $this->failCommand('gateway_unavailable', 'Gateway connection is required to list node roles.', []);
        }

        return $this->respondSuccess($dto->node, $dto->roles);
    }

    /**
     * @param  list<array<string, mixed>>  $roles
     */
    private function respondSuccess(string $nodeName, array $roles): int
    {
        if ($this->wantsJson()) {
            $this->line(json_encode([
                'success' => [
                    'data' => [
                        'node' => $nodeName,
                        'roles' => $roles,
                    ],
                ],
            ], JSON_THROW_ON_ERROR));

            return self::SUCCESS;
        }

        if ($roles === []) {
            $this->line("Node '{$nodeName}' has no role assignments.");

            return self::SUCCESS;
        }

        table(
            headers: ['ROLE', 'STATUS', 'SETTINGS', 'LAST ERROR', 'CONVERGED AT'],
            rows: array_map(
                static fn (array $role): array => [
                    $role['role'],
                    $role['status'],
                    json_encode($role['settings'], JSON_THROW_ON_ERROR),
                    $role['last_error'] ?? '—',
                    $role['converged_at'] ?? '—',
                ],
                $roles,
            ),
        );

        return self::SUCCESS;
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
