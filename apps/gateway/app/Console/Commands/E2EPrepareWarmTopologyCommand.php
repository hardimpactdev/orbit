<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\E2E\Support\E2EConfig;
use App\E2E\Support\E2EPhaseTimer;
use App\E2E\Support\E2EPreparedTopology;
use App\E2E\Support\E2ETopologyKind;
use App\E2E\Support\IncusHostPool;
use App\E2E\Support\IncusTopologyProvider;
use App\E2E\Support\IncusWarmTopologyPool;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use RuntimeException;

#[Signature('e2e:prepare-warm-topology
    {kind=operator_gateway_app-dev_app-prod_agent : Topology kind to warm (operator|operator_gateway|operator_gateway_app-dev|operator_gateway_app-dev_app-prod|operator_gateway_agent|operator_gateway_app-dev_app-prod_agent|operator_gateway_app-prod_ingress|operator_gateway_app-dev_websocket|operator_gateway_app-dev_app-prod_websocket|operator_gateway_app-dev_app-prod_agent_websocket)}
    {--force : Create stateful warm snapshots}
    {--rebuild : Rebuild selected warm snapshot slots even when they already exist}
    {--slots= : Warm snapshot slots to prepare on the Incus host}
    {--json : Output as JSON}')]
#[Description('Prepare Incus stateful warm snapshots used by ephemeral E2E tests')]
class E2EPrepareWarmTopologyCommand extends Command
{
    #[\Override]
    protected $hidden = true;

    public function handle(): int
    {
        $kindValue = (string) $this->argument('kind');
        $kind = E2ETopologyKind::tryFromInput($kindValue);

        if ($kind === null) {
            return $this->failValidation("Invalid topology kind [{$kindValue}]. Supported: ".E2EPreparedTopology::supportedKindsForHelp().'.');
        }

        if (! E2EPreparedTopology::supportsKind($kind)) {
            return $this->failValidation(E2EPreparedTopology::unsupportedKindMessage($kind));
        }

        $config = E2EConfig::fromEnvironment();

        if (! in_array('incus', $config->providerNames, true)) {
            return $this->failCommand('No Incus provider configured. Set ORBIT_E2E_PROVIDER or ORBIT_E2E_PROVIDERS to include incus.');
        }

        $host = IncusHostPool::fromEnvironment($config)->first();

        if ($host === null) {
            return $this->failCommand('No Incus hosts configured. Set ORBIT_E2E_INCUS_HOSTS or ORBIT_E2E_HOST.');
        }

        try {
            $slots = $this->selectedSlots($config, $kind, $host->config->host);
        } catch (RuntimeException $exception) {
            return $this->failValidation($exception->getMessage());
        }

        $planned = $this->plannedSlots($kind, $host->config->host, $slots);

        if (! (bool) $this->option('force')) {
            return $this->renderDryRun($kind, $host->config->host, $planned);
        }

        $timer = new E2EPhaseTimer(stream: ! $this->laravel->runningUnitTests());

        try {
            $manifest = $timer->measure(
                'warm.prepare',
                fn (): array => new IncusTopologyProvider($config)->prepareWarmSnapshots($kind, $slots, $timer, replaceExisting: (bool) $this->option('rebuild')),
            );
        } catch (RuntimeException $exception) {
            return $this->failCommand($exception->getMessage());
        } finally {
            $timer->flush('prepare-warm-topology');
        }

        if ((bool) $this->option('json')) {
            $this->line(json_encode([
                'success' => [
                    'data' => [
                        'provider' => 'incus',
                        'dry_run' => false,
                        'kind' => $kind->value,
                        'host' => $host->config->host,
                        'slots' => $manifest,
                    ],
                ],
            ], JSON_THROW_ON_ERROR));

            return self::SUCCESS;
        }

        $this->info("Built warm topology [{$kind->value}].");

        foreach ($manifest as $slot) {
            $verb = ($slot['reused'] ?? false) ? 'reused' : 'created';

            $this->line("{$verb}: slot {$slot['slot']} (snapshot: {$slot['snapshot']})");
        }

        return self::SUCCESS;
    }

    private function selectedSlots(E2EConfig $config, E2ETopologyKind $kind, string $host): int
    {
        $option = $this->option('slots');
        $slots = null;

        if (is_string($option) && trim($option) !== '') {
            if (! ctype_digit(trim($option))) {
                throw new RuntimeException('--slots must be a positive integer.');
            }

            $slots = (int) trim($option);
        }

        $slots ??= IncusWarmTopologyPool::configuredSlotsForHost($config, $kind, $host);

        if ($slots < 1) {
            throw new RuntimeException('Warm topology preparation needs at least one slot.');
        }

        $maxSlots = IncusWarmTopologyPool::maxSlotsForHost($config, $kind, $host);

        if ($slots > $maxSlots) {
            throw new RuntimeException("Warm topology {$kind->value} requested {$slots} slots, but {$host} can fit {$maxSlots} warm slot(s).");
        }

        return $slots;
    }

    /**
     * @return list<array{host: string, slot: int, run_id: string, instances: list<string>, snapshot: string}>
     */
    private function plannedSlots(E2ETopologyKind $kind, string $host, int $slots): array
    {
        $planned = [];

        for ($slot = 1; $slot <= $slots; $slot++) {
            $planned[] = [
                'host' => $host,
                'slot' => $slot,
                'run_id' => IncusWarmTopologyPool::runId($kind, $slot),
                'instances' => IncusWarmTopologyPool::instanceNames($kind, $slot),
                'snapshot' => IncusWarmTopologyPool::SnapshotName,
            ];
        }

        return $planned;
    }

    /**
     * @param  list<array{host: string, slot: int, run_id: string, instances: list<string>, snapshot: string}>  $planned
     */
    private function renderDryRun(E2ETopologyKind $kind, string $host, array $planned): int
    {
        if ((bool) $this->option('json')) {
            $this->line(json_encode([
                'success' => [
                    'data' => [
                        'provider' => 'incus',
                        'dry_run' => true,
                        'kind' => $kind->value,
                        'host' => $host,
                        'slots' => $planned,
                    ],
                ],
            ], JSON_THROW_ON_ERROR));

            return self::SUCCESS;
        }

        $this->line('Dry run. Pass --force to create Incus warm stateful snapshots.');
        $this->line("requested topology: {$kind->value}");
        $this->line("host: {$host}");

        foreach ($planned as $slot) {
            $this->line("planned: slot {$slot['slot']} (snapshot: {$slot['snapshot']})");

            foreach ($slot['instances'] as $instance) {
                $this->line("  instance: {$instance}");
            }
        }

        return self::SUCCESS;
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
                    'code' => 'warm_topology_prepare_failed',
                    'message' => $message,
                ],
            ], JSON_THROW_ON_ERROR));

            return self::FAILURE;
        }

        $this->error($message);

        return self::FAILURE;
    }
}
