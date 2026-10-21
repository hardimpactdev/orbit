<?php

declare(strict_types=1);

use App\Domain\AppDev\RuntimeConvergenceException;
use App\Domain\Nodes\ManagedUserAccount;
use App\Domain\Nodes\ManagedUserAccountResolver;
use App\Domain\Nodes\RoleName;
use App\Infrastructure\AppDev\DevelopmentSshExecutor;
use App\Infrastructure\Nodes\CiRunnerHostProgram;
use App\Infrastructure\Nodes\Roles\CiRunnerRoleBaseline;
use App\Infrastructure\Nodes\Roles\NodeRolePrerequisiteCommandFactory;
use App\Infrastructure\Processes\CommandResult;
use App\Infrastructure\RemotePrograms\RemoteProgramCatalog;
use App\Infrastructure\RemotePrograms\SshProgramRunner;
use App\Infrastructure\Ssh\KnownHostsStore;
use App\Infrastructure\Ssh\SshKeyProvider;
use Tests\Support\FakeNodeRoleFirewallManager;
use Tests\Support\Fleet\FleetFixtures;
use Tests\Support\Fleet\ScriptedSshExecutor;

use function Pest\Laravel\mock;

/** @return array{CiRunnerRoleBaseline, ScriptedSshExecutor, FakeNodeRoleFirewallManager} */
function ci_runner_baseline(): array
{
    $keys = mock(SshKeyProvider::class);
    $keys->shouldReceive('privateKeyPath')->andReturn('/key');
    $known = mock(KnownHostsStore::class);
    $known->shouldReceive('path')->andReturn('/known');
    $accounts = mock(ManagedUserAccountResolver::class);
    $accounts->shouldReceive('resolve')->andReturn(new ManagedUserAccount('orbit', 'orbit', '/home/orbit'));
    $ssh = new ScriptedSshExecutor;
    $firewall = new FakeNodeRoleFirewallManager;

    return [new CiRunnerRoleBaseline(new NodeRolePrerequisiteCommandFactory, $accounts,
        new DevelopmentSshExecutor($ssh, $keys, $known), $firewall,
        new CiRunnerHostProgram(new SshProgramRunner($ssh, $keys, $known, new RemoteProgramCatalog), new RemoteProgramCatalog)), $ssh, $firewall];
}

it('installs Incus and runs the host baseline before opening its managed firewall rule', function (): void {
    $node = FleetFixtures::node('ci-only', [RoleName::CiRunner]);
    [$baseline, $ssh, $firewall] = ci_runner_baseline();
    $ssh->on('/ci-runners\.host$/', function () use ($ssh, $firewall): CommandResult {
        expect($ssh->commands[0]->arguments)->toContain('incus')->not->toContain('docker.io', 'caddy');
        expect($firewall->commands)->toBe([]);

        expect(json_decode(explode("\0", $ssh->commands[1]->input)[1], true)['action'])->toBe('converge');

        return ScriptedSshExecutor::ok("{\"outcome\":\"converged\",\"data\":{}}\n");
    });

    $baseline->converge($node, $node->roles()->sole());

    expect($firewall->commands)->toBe(['converge:ci-runner']);
    expect($ssh->lines())->toHaveCount(2)
        ->and($ssh->commands[1]->arguments)->toContain('sudo', 'root', 'python3', '-I', 'ci-runners.host');
});

it('reports host failure and never opens the bridge allowance', function (): void {
    $node = FleetFixtures::node('failed-ci', [RoleName::CiRunner]);
    [$baseline, $ssh, $firewall] = ci_runner_baseline();
    $ssh->on('/ci-runners\.host$/', ScriptedSshExecutor::fail(stderr: 'Incus unavailable'));

    expect(fn () => $baseline->converge($node, $node->roles()->sole()))->toThrow(RuntimeConvergenceException::class);
    expect($firewall->commands)->toBe([]);
});

it('refuses occupied removal before closing the firewall rule', function (bool $purge): void {
    $node = FleetFixtures::node('occupied-ci', [RoleName::CiRunner]);
    [$baseline, $ssh, $firewall] = ci_runner_baseline();
    $ssh->on('/ci-runners\.host$/', ScriptedSshExecutor::ok("{\"outcome\":\"occupied\",\"data\":{}}\n"));

    expect(fn () => $baseline->remove($node, $node->roles()->sole(), $purge))
        ->toThrow(RuntimeConvergenceException::class);
    expect($firewall->commands)->toBe([]);
})->with([false, true]);

it('closes its firewall after removing an empty host and does no remote work offline', function (): void {
    $node = FleetFixtures::node('empty-ci', [RoleName::CiRunner]);
    [$baseline, $ssh, $firewall] = ci_runner_baseline();
    $assignment = $node->roles()->sole();
    $ssh->on('/ci-runners\.host$/', ScriptedSshExecutor::ok("{\"outcome\":\"retained\",\"data\":{}}\n"));

    $baseline->remove($node, $assignment, false);
    $baseline->removeUnreachable($node, $assignment);

    expect($firewall->commands)->toBe(['remove:ci-runner']);
    expect($ssh->commands)->toHaveCount(1)
        ->and(json_decode(explode("\0", $ssh->commands[0]->input)[1], true)['action'])->toBe('remove');
});

it('rejects a host refusal or malformed result before opening its firewall', function (string $output): void {
    $node = FleetFixtures::node('refused-ci', [RoleName::CiRunner]);
    [$baseline, $ssh, $firewall] = ci_runner_baseline();
    $ssh->on('/ci-runners\.host$/', ScriptedSshExecutor::ok($output));

    expect(fn () => $baseline->converge($node, $node->roles()->sole()))->toThrow(RuntimeConvergenceException::class);
    expect($firewall->commands)->toBe([]);
})->with(["{\"outcome\":\"failed\",\"data\":{}}\n", "{\"outcome\":\"retained\",\"data\":{}}\n", "1\n"]);

it('closes its firewall only after a successful empty purge', function (): void {
    $node = FleetFixtures::node('purged-ci', [RoleName::CiRunner]);
    [$baseline, $ssh, $firewall] = ci_runner_baseline();
    $ssh->on('/ci-runners\.host$/', ScriptedSshExecutor::ok("{\"outcome\":\"removed\",\"data\":{}}\n"));

    $baseline->remove($node, $node->roles()->sole(), true);

    expect($firewall->commands)->toBe(['remove:ci-runner'])
        ->and(json_decode(explode("\0", $ssh->commands[0]->input)[1], true)['action'])->toBe('purge');
});
