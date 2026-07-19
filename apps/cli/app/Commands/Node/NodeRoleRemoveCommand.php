<?php

declare(strict_types=1);

namespace App\Commands\Node;

use App\Commands\Concerns\WithStepTree;
use App\Commands\GatewayCommand;
use App\Exceptions\GatewayApiException;
use RuntimeException;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputOption;

/** @mago-expect lint:cyclomatic-complexity */
final class NodeRoleRemoveCommand extends GatewayCommand
{
    use WithStepTree;

    #[\Override]
    protected $name = 'node role:remove';

    #[\Override]
    protected $description = 'Remove a workload role from a node.';

    protected function configure(): void
    {
        parent::configure();

        $this->addArgument('node', InputArgument::REQUIRED, 'Name of the node');
        $this->addArgument('role', InputArgument::REQUIRED, 'Role to remove');
        $this->addOption(
            'force',
            null,
            InputOption::VALUE_NONE,
            'Confirm destructive role removal and dependent cleanup',
        );
        $this->addOption('purge-data', null, InputOption::VALUE_NONE, 'Purge role-owned data');
        $this->addOption('json', null, InputOption::VALUE_NONE, 'Output JSON');
    }

    public function handle(): int
    {
        $node = (string) $this->argument('node');
        $role = (string) $this->argument('role');
        $force = (bool) $this->option('force');
        $purgeData = (bool) $this->option('purge-data');

        if (in_array($role, ['gateway', 'vpn', 'router'], true)) {
            return $this->renderFailure(
                'validation_failed',
                "Role '{$role}' is gateway-coupled and cannot be assigned independently.",
                ['field' => 'role', 'role' => $role],
            );
        }

        if (! $force) {
            $confirmation = $this->confirmRemoval($node, $role, $purgeData);

            if ($confirmation !== null) {
                return $confirmation;
            }
        }

        if ($this->wantsJson()) {
            try {
                $response = $this->removeRole($node, $role, $purgeData);
            } catch (GatewayApiException $exception) {
                return $this->renderGatewayFailure($exception);
            }

            return $this->renderSuccess($response);
        }

        return $this->renderRoleRemoveTree($node, $role, $purgeData);
    }

    private function renderRoleRemoveTree(string $node, string $role, bool $purgeData): int
    {
        $outcome = $this->runStepOperation(
            'Removing Node Role',
            [
                ['label' => 'Validate removal', 'doneLabel' => 'Validated removal'],
                ['label' => 'Remove role convergence', 'doneLabel' => 'Removed role convergence'],
            ],
            work: fn (): array => $this->removeRoleForHuman($node, $role, $purgeData),
            doneFooter: $purgeData
                ? "Role '{$role}' removed from '{$node}' with data purged"
                : "Role '{$role}' removed from '{$node}'",
        );

        return $outcome->isCompleted() ? self::SUCCESS : self::FAILURE;
    }

    /**
     * @return array<string, mixed>
     */
    private function removeRole(string $node, string $role, bool $purgeData): array
    {
        return $this->gatewayDelete('/api/nodes/'.rawurlencode($node).'/roles/'.rawurlencode($role), [
            'force' => true,
            'purge_data' => $purgeData,
        ]);
    }

    private function confirmRemoval(string $node, string $role, bool $purgeData): ?int
    {
        try {
            $this->gatewayDelete('/api/nodes/'.rawurlencode($node).'/roles/'.rawurlencode($role), [
                'force' => false,
                'purge_data' => $purgeData,
            ]);
        } catch (GatewayApiException $exception) {
            if (! $this->isDestructiveConsentFailure($exception)) {
                return $this->renderGatewayFailure($exception);
            }

            $dependents = $this->dependentResources($exception);

            if ($this->wantsJson() || ! $this->input->isInteractive()) {
                return $this->renderGatewayFailure($exception);
            }

            $this->renderDependentResources($dependents);

            if ($this->confirm("Remove role '{$role}' from '{$node}'?", default: false)) {
                return null;
            }

            return $this->renderFailure('validation_failed', 'Operation cancelled.', [
                'field' => 'force',
                'reason' => 'destructive_consent_required',
                'node' => $node,
                'role' => $role,
                'dependents' => $dependents,
            ]);
        }

        return $this->renderFailure(
            'validation_failed',
            'The gateway did not return a node role removal preview.',
            ['field' => 'force', 'reason' => 'destructive_consent_required'],
        );
    }

    private function isDestructiveConsentFailure(GatewayApiException $exception): bool
    {
        $meta = $exception->gatewayErrorMeta();

        return (
            $exception->gatewayErrorCode() === 'validation_failed'
            && ($meta['field'] ?? null) === 'force'
            && ($meta['reason'] ?? null) === 'destructive_consent_required'
        );
    }

    /**
     * @return list<string>
     */
    private function dependentResources(GatewayApiException $exception): array
    {
        $dependents = $exception->gatewayErrorMeta()['dependents'] ?? null;

        if (! is_array($dependents)) {
            return [];
        }

        return array_values(array_filter($dependents, is_string(...)));
    }

    /**
     * @param  list<string>  $dependents
     */
    private function renderDependentResources(array $dependents): void
    {
        if ($dependents === []) {
            return;
        }

        $this->line('Dependent resources:');

        foreach ($dependents as $dependent) {
            $this->line("  - {$dependent}");
        }
    }

    /**
     * Run the role cleanup inside the progress tree, re-throwing gateway
     * failures with their operator-facing message so the failed footer renders
     * the documented prose rather than a JSON envelope.
     *
     * @return array<string, mixed>
     */
    private function removeRoleForHuman(string $node, string $role, bool $purgeData): array
    {
        try {
            return $this->removeRole($node, $role, $purgeData);
        } catch (GatewayApiException $exception) {
            throw new RuntimeException(
                $exception->gatewayErrorMessage() ?? $exception->getMessage(),
                previous: $exception,
            );
        }
    }
}
