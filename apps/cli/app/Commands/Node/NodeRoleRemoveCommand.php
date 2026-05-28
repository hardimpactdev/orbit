<?php

declare(strict_types=1);

namespace App\Commands\Node;

use App\Commands\GatewayCommand;
use App\Exceptions\GatewayApiException;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputOption;

final class NodeRoleRemoveCommand extends GatewayCommand
{
    #[\Override]
    protected $name = 'node role:remove';

    #[\Override]
    protected $description = 'Remove a hosted role from a node.';

    protected function configure(): void
    {
        parent::configure();

        $this->addArgument('node', InputArgument::REQUIRED, 'Name of the node');
        $this->addArgument('role', InputArgument::REQUIRED, 'Role to remove');
        $this->addOption('force', null, InputOption::VALUE_NONE, 'Confirm destructive dependent cleanup');
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

        if ($purgeData && ! $force) {
            return $this->renderFailure('validation_failed', 'The purge-data option requires --force.', ['field' => 'purge-data']);
        }

        if (! $force) {
            return $this->renderFailure('validation_failed', 'Use --force to remove this role.', ['field' => 'force']);
        }

        try {
            $response = $this->gatewayDelete('/api/nodes/'.rawurlencode($node).'/roles/'.rawurlencode($role), [
                'force' => true,
                'purge_data' => $purgeData,
            ]);
        } catch (GatewayApiException $exception) {
            return $this->renderGatewayFailure($exception);
        }

        return $this->renderSuccess($response);
    }
}
