<?php

declare(strict_types=1);

use App\E2E\Support\E2EConfig;
use App\E2E\Support\E2ECurrentCheckout;
use App\E2E\Support\E2ERun;
use App\E2E\Support\E2ETopologyCache;
use App\E2E\Support\E2ETopologyFactory;
use App\E2E\Support\E2ETopologyHarness;
use App\E2E\Support\E2ETopologyKind;
use App\E2E\Support\E2ETopologyLease;
use App\E2E\Support\E2ETopologyUnavailable;

/**
 * @template TValue
 *
 * @param  callable(): TValue  $callback
 * @return TValue
 */
function e2eProvisionStep(string $step, callable $callback): mixed
{
    fwrite(STDERR, "[e2e-provision] {$step}\n");

    try {
        return $callback();
    } catch (Throwable $throwable) {
        fwrite(STDERR, "[e2e-provision] failed at step: {$step}\n");

        throw $throwable;
    }
}

/**
 * Acquire a prepared E2E topology and wrap it in the small Pest-facing helper
 * API used by feature tests.
 *
 * @param  array<string, string>|null  $sshUsers
 */
function e2eTopology(E2ETopologyKind $kind, ?array $sshUsers = null, bool $withGatewayApi = false): E2ETopologyHarness
{
    $sshUsers ??= ['control' => E2EConfig::fromEnvironment()->controlUser];
    $withGatewayApi = $withGatewayApi || e2eGatewayApiByDefault();

    if (E2ETopologyCache::enabled()) {
        return E2ETopologyCache::acquire($kind, $sshUsers, $withGatewayApi);
    }

    $factory = E2ETopologyFactory::fromEnvironment()
        ->withSshUsers($sshUsers);

    if ($withGatewayApi) {
        $factory = $factory->withGatewayApi();
    }

    try {
        $lease = $factory->require($kind);
    } catch (E2ETopologyUnavailable $exception) {
        test()->markTestSkipped($exception->getMessage());
    }

    return new E2ETopologyHarness($lease);
}

function e2eGatewayApiByDefault(): bool
{
    $value = getenv('ORBIT_E2E_GATEWAY_API');

    return is_string($value)
        && in_array(strtolower($value), ['1', 'true', 'yes'], true);
}

/**
 * Install the current checkout into selected topology roles and return their
 * remote checkout paths.
 *
 * @param  list<string>|null  $roles
 * @param  array<string, string>  $users
 * @return array<string, string>
 */
function e2eCheckout(E2ETopologyLease|E2ETopologyHarness $topology, ?array $roles = null, array $users = []): array
{
    if ($topology instanceof E2ETopologyHarness) {
        return $topology->withCurrentCheckout($roles, $users)->checkouts();
    }

    return E2ECurrentCheckout::installOnTopology($topology, $roles, $users);
}

function e2eProvisionCleanup(bool $passed, ?E2ERun $run = null, E2ETopologyLease|E2ETopologyHarness|null $topology = null): void
{
    if ($passed || ! e2eProvisionKeepsFailures()) {
        $run?->cleanup();
        $topology?->cleanup();

        return;
    }

    e2eProvisionReportDangling([
        ...($run?->instanceNames() ?? []),
        ...($topology?->instanceNames() ?? []),
    ]);
}

function e2eProvisionKeepsFailures(): bool
{
    $value = getenv('ORBIT_E2E_KEEP_ON_FAILURE');

    return ! is_string($value) || ! in_array(strtolower($value), ['0', 'false', 'no'], true);
}

/**
 * @param  list<string>  $instanceNames
 */
function e2eProvisionReportDangling(array $instanceNames): void
{
    $instanceNames = array_values(array_unique(array_filter($instanceNames)));

    if ($instanceNames === []) {
        fwrite(STDERR, "E2E provision test failed; no tracked instances were available to report.\n");

        return;
    }

    fwrite(STDERR, "E2E provision test failed; keeping instances for inspection:\n");

    foreach ($instanceNames as $instanceName) {
        fwrite(STDERR, "  - {$instanceName}\n");
    }

    fwrite(STDERR, "Reap later with: composer e2e:reap-incus -- --force --older-than=0m\n");
    fwrite(STDERR, "Set ORBIT_E2E_KEEP_ON_FAILURE=0 to restore cleanup-on-failure behavior.\n");
}
