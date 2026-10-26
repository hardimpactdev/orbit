<?php

declare(strict_types=1);

use App\Domain\AppDev\RuntimeConvergenceException;
use App\Domain\Nodes\ManagedUserAccount;
use App\Domain\Nodes\ManagedUserAccountResolver;
use App\Domain\Nodes\RoleName;
use App\Infrastructure\Nodes\AgentCaddyPackage;
use App\Infrastructure\Nodes\CiRunnerHostProgram;
use App\Infrastructure\Nodes\Roles\CiRunnerRoleBaseline;
use App\Infrastructure\Nodes\Roles\RolePrerequisites;
use Tests\Support\FakeAgentCommandRunner;
use Tests\Support\FakeNodeRoleFirewallManager;
use Tests\Support\Fleet\FleetFixtures;

use function Pest\Laravel\mock;

/**
 * A CI runner baseline whose agent answers the prerequisites and gives `ci-runner.host` the outcome `$outcome`, or
 * refuses it when `$outcome` is null.
 *
 * @return array{CiRunnerRoleBaseline, FakeAgentCommandRunner, FakeNodeRoleFirewallManager}
 */
function ci_runner_baseline(?string $outcome, ?Closure $during = null): array
{
    $accounts = mock(ManagedUserAccountResolver::class);
    $accounts->shouldReceive('resolve')->andReturn(new ManagedUserAccount('orbit', 'orbit', '/home/orbit'));
    $firewall = new FakeNodeRoleFirewallManager;
    $agents = (new FakeAgentCommandRunner)->answer('role.install-prerequisites', []);
    $outcome === null
        ? $agents->refuse('ci-runner.host', 'ci_runner.host_failed', ['reason' => 'Incus unavailable'])
        : $agents->answer('ci-runner.host', static function () use ($outcome, $during): array {
            if ($during !== null) {
                $during();
            }

            return ['outcome' => $outcome];
        });

    return [new CiRunnerRoleBaseline(new RolePrerequisites($agents, new AgentCaddyPackage($agents)), $accounts, $firewall, new CiRunnerHostProgram($agents)), $agents, $firewall];
}

it('installs Incus and runs the host baseline before opening its managed firewall rule', function (): void {
    $node = FleetFixtures::node('ci-only', [RoleName::CiRunner]);
    $firewallAtHost = null;
    $firewall = null;
    [$baseline, $agents, $firewall] = ci_runner_baseline('converged', static function () use (&$firewall, &$firewallAtHost): void {
        $firewallAtHost = $firewall->commands;
    });

    $baseline->converge($node, $node->roles()->sole());

    expect($agents->commands())->toBe(['role.install-prerequisites', 'ci-runner.host'])
        ->and($agents->calls[0]['input']['packages'])->toContain('incus', 'incus-base', 'incus-client')->not->toContain('docker.io', 'caddy')
        ->and($agents->calls[1]['input'])->toBe(['action' => 'converge'])
        ->and($firewallAtHost)->toBe([])
        ->and($firewall->commands)->toBe(['converge:ci-runner']);
});

it('reports host failure and never opens the bridge allowance', function (): void {
    $node = FleetFixtures::node('failed-ci', [RoleName::CiRunner]);
    [$baseline, , $firewall] = ci_runner_baseline(null);

    expect(fn () => $baseline->converge($node, $node->roles()->sole()))->toThrow(RuntimeConvergenceException::class);
    expect($firewall->commands)->toBe([]);
});

it('refuses occupied removal before closing the firewall rule', function (bool $purge): void {
    $node = FleetFixtures::node('occupied-ci', [RoleName::CiRunner]);
    [$baseline, , $firewall] = ci_runner_baseline('occupied');

    expect(fn () => $baseline->remove($node, $node->roles()->sole(), $purge))
        ->toThrow(RuntimeConvergenceException::class);
    expect($firewall->commands)->toBe([]);
})->with([false, true]);

it('closes its firewall after removing an empty host and does no remote work offline', function (): void {
    $node = FleetFixtures::node('empty-ci', [RoleName::CiRunner]);
    [$baseline, $agents, $firewall] = ci_runner_baseline('retained');
    $assignment = $node->roles()->sole();

    $baseline->remove($node, $assignment, false);
    $baseline->removeUnreachable($node, $assignment);

    expect($firewall->commands)->toBe(['remove:ci-runner'])
        ->and($agents->calls)->toHaveCount(1)
        ->and($agents->calls[0]['input'])->toBe(['action' => 'remove']);
});

it('rejects an outcome other than converged before opening its firewall', function (string $outcome): void {
    $node = FleetFixtures::node('refused-ci', [RoleName::CiRunner]);
    [$baseline, , $firewall] = ci_runner_baseline($outcome);

    expect(fn () => $baseline->converge($node, $node->roles()->sole()))->toThrow(RuntimeConvergenceException::class);
    expect($firewall->commands)->toBe([]);
})->with(['retained', 'drift', '']);

it('closes its firewall only after a successful empty purge', function (): void {
    $node = FleetFixtures::node('purged-ci', [RoleName::CiRunner]);
    [$baseline, $agents, $firewall] = ci_runner_baseline('removed');

    $baseline->remove($node, $node->roles()->sole(), true);

    expect($firewall->commands)->toBe(['remove:ci-runner'])
        ->and($agents->calls[0]['input'])->toBe(['action' => 'purge']);
});
