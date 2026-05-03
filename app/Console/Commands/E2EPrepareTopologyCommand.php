<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use RuntimeException;
use Tests\E2E\Support\E2EConfig;
use Tests\E2E\Support\E2ETopologyKind;
use Tests\E2E\Support\IncusHostPool;
use Tests\E2E\Support\IncusTopologyTemplate;

#[Signature('e2e:prepare-topology
    {kind=control-gateway-dev-prod : Topology kind to prepare (control|control-gateway|control-gateway-dev|control-gateway-dev-prod)}
    {--force : Create Incus topology templates}
    {--json : Output as JSON}')]
#[Description('Prepare Incus topology templates used by ephemeral E2E tests')]
class E2EPrepareTopologyCommand extends Command
{
    protected $hidden = true;

    public function handle(): int
    {
        $kindValue = (string) $this->argument('kind');

        $kind = E2ETopologyKind::tryFrom($kindValue);

        if ($kind === null) {
            return $this->failValidation("Invalid topology kind [{$kindValue}]. Supported: control, control-gateway, control-gateway-dev, control-gateway-dev-prod.");
        }

        $config = E2EConfig::fromEnvironment();

        if (! in_array('incus', $config->providerNames, true)) {
            return $this->failCommand('No Incus provider configured. Set ORBIT_E2E_PROVIDER or ORBIT_E2E_PROVIDERS to include incus.');
        }

        $roles = IncusTopologyTemplate::rolesFor($kind);
        $templates = [];

        foreach ($roles as $role) {
            $templates[] = [
                'role' => $role,
                'name' => IncusTopologyTemplate::templateName($kind, $role),
                'snapshot' => 'clean',
            ];
        }

        $dryRun = ! (bool) $this->option('force');

        if ($dryRun) {
            $result = [
                'provider' => 'incus',
                'dry_run' => true,
                'kind' => $kind->value,
                'templates' => $templates,
            ];

            if ((bool) $this->option('json')) {
                $this->line(json_encode(['success' => ['data' => $result]], JSON_THROW_ON_ERROR));

                return self::SUCCESS;
            }

            $this->line('Dry run. Pass --force to create Incus topology templates.');

            foreach ($templates as $template) {
                $this->line("planned: {$template['name']} (snapshot: {$template['snapshot']})");
            }

            return self::SUCCESS;
        }

        $hostPool = IncusHostPool::fromEnvironment($config);
        $host = $hostPool->firstAvailableFor($kind);

        if ($host === null) {
            return $this->failCommand("No Incus host has templates available for kind [{$kind->value}].");
        }

        try {
            // TODO: Implement actual topology template builds.
            // This involves creating VMs from prepared images, running provisioning logic,
            // stopping VMs, and snapshotting each as 'clean'.
            throw new RuntimeException('Actual topology template builds are not yet implemented. Use dry-run to preview the plan.');
        } catch (RuntimeException $exception) {
            return $this->failCommand($exception->getMessage());
        }
    }

    private function failValidation(string $message): int
    {
        if ((bool) $this->option('json')) {
            $this->line(json_encode([
                'error' => [
                    'code' => 'validation_failed',
                    'message' => $message,
                ],
            ], JSON_THROW_ON_ERROR));

            return self::FAILURE;
        }

        $this->error($message);

        return self::FAILURE;
    }

    private function failCommand(string $message): int
    {
        if ((bool) $this->option('json')) {
            $this->line(json_encode([
                'error' => [
                    'code' => 'topology_prepare_failed',
                    'message' => $message,
                ],
            ], JSON_THROW_ON_ERROR));

            return self::FAILURE;
        }

        $this->error($message);

        return self::FAILURE;
    }
}
