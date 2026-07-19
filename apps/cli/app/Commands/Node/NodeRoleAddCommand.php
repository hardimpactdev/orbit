<?php

declare(strict_types=1);

namespace App\Commands\Node;

use App\Commands\Concerns\WithStepTree;
use App\Commands\GatewayCommand;
use App\Exceptions\GatewayApiException;
use App\Exceptions\NodeWriteInputException;
use App\Services\Node\NodeRoleAddPayloadBuilder;
use RuntimeException;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputOption;

final class NodeRoleAddCommand extends GatewayCommand
{
    use WithStepTree;

    #[\Override]
    protected $name = 'node role:add';

    #[\Override]
    protected $description = 'Add a workload role to a node.';

    protected function configure(): void
    {
        parent::configure();

        $this->addArgument('node', InputArgument::REQUIRED, 'Name of the node');
        $this->addArgument('role', InputArgument::REQUIRED, 'Role to add');
        $this->addOption(
            'valkey-node',
            null,
            InputOption::VALUE_REQUIRED,
            'Existing database node for websocket Valkey',
        );
        $this->addOption(
            'postgres-node',
            null,
            InputOption::VALUE_REQUIRED,
            'Existing database node for analytics PostgreSQL',
        );
        $this->addOption(
            'postgres-process',
            null,
            InputOption::VALUE_REQUIRED,
            'PostgreSQL process for analytics',
        );
        $this->addOption(
            'clickhouse-node',
            null,
            InputOption::VALUE_REQUIRED,
            'Existing database node for analytics ClickHouse',
        );
        $this->addOption('s3-data-path', null, InputOption::VALUE_REQUIRED, 'Host data path for the s3 role');
        $this->addOption('json', null, InputOption::VALUE_NONE, 'Output JSON');
    }

    public function handle(NodeRoleAddPayloadBuilder $payloadBuilder): int
    {
        $node = (string) $this->argument('node');
        $role = (string) $this->argument('role');

        try {
            $payload = $payloadBuilder->build(
                role: $role,
                valkeyNode: $this->stringOption('valkey-node'),
                postgresNode: $this->stringOption('postgres-node'),
                postgresProcess: $this->stringOption('postgres-process'),
                clickhouseNode: $this->stringOption('clickhouse-node'),
                s3DataPath: $this->stringOption('s3-data-path'),
            );
        } catch (NodeWriteInputException $exception) {
            return $this->renderFailure($exception->orbitCode, $exception->getMessage(), $exception->meta);
        }

        if ($this->wantsJson()) {
            try {
                $response = $this->addRole($node, $payload);
            } catch (GatewayApiException $exception) {
                return $this->renderGatewayFailure($exception);
            }

            return $this->renderSuccess($response);
        }

        return $this->renderRoleAddTree($node, $role, $payload);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function renderRoleAddTree(string $node, string $role, array $payload): int
    {
        $outcome = $this->runStepOperation(
            'Adding Node Role',
            [
                ['label' => 'Validate request', 'doneLabel' => 'Validated request'],
                ['label' => 'Apply role convergence', 'doneLabel' => 'Applied role convergence'],
            ],
            work: fn (): array => $this->addRoleForHuman($node, $payload),
            doneFooter: "Role '{$role}' added to '{$node}'",
        );

        return $outcome->isCompleted() ? self::SUCCESS : self::FAILURE;
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function addRole(string $node, array $payload): array
    {
        return $this->gatewayPost('/api/nodes/'.rawurlencode($node).'/roles', $payload);
    }

    /**
     * Run the role convergence inside the progress tree, re-throwing gateway
     * failures with their operator-facing message so the failed footer renders
     * the documented prose rather than a JSON envelope.
     *
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function addRoleForHuman(string $node, array $payload): array
    {
        try {
            return $this->addRole($node, $payload);
        } catch (GatewayApiException $exception) {
            throw new RuntimeException(
                $exception->gatewayErrorMessage() ?? $exception->getMessage(),
                previous: $exception,
            );
        }
    }

    private function stringOption(string $key): ?string
    {
        $value = $this->option($key);

        return is_string($value) && $value !== '' ? $value : null;
    }
}
