<?php

declare(strict_types=1);

use App\Domain\Fleet\CliConvergePlan;
use App\Domain\Fleet\DesiredFleetState;
use App\Domain\Fleet\FleetRolloutMembership;
use App\Domain\Gateway\GatewayServingHost;
use App\Domain\Nodes\NodeEnrollmentCandidate;
use App\Domain\Nodes\RoleName;
use App\Domain\Routes\RouteRenderContext;
use App\Domain\Shared\LifecycleStatus;
use App\Domain\Shared\ResourceOperationException;
use App\Infrastructure\AgentCommands\AgentDelivery;
use App\Models\Node;
use App\Models\Operation;
use App\Models\Setting;
use Illuminate\Cache\ArrayStore;
use Illuminate\Cache\Repository;
use Tests\Support\Fleet\FakeCliReleaseCatalog;
use Tests\Support\Fleet\FakeReleaseHistory;
use Tests\Support\Fleet\FleetFixtures;

beforeEach(function (): void {
    config()->set('app.version', str_repeat('a', 40));
    app()->instance(DesiredFleetState::class, new DesiredFleetState(new FakeReleaseHistory,
        new FakeCliReleaseCatalog, new Repository(new ArrayStore), app(AgentDelivery::class)));
});

/** @param array<string,mixed> $changes */
function cliEnrollmentCandidate(Node $node, array $changes = []): NodeEnrollmentCandidate
{
    return new NodeEnrollmentCandidate(...array_replace([
        'nodeId' => $node->id, 'name' => $node->name, 'user' => 'next-orbit', 'platform' => 'linux',
        'architecture' => 'amd64', 'wireguardIp' => '10.44.0.201', 'publicSshHost' => '192.0.2.201',
        'publicSshPort' => 22, 'clusterId' => null, 'tld' => null, 'lanIp' => null,
        'wireguardEndpointOverride' => null, 'dnsServerOverride' => null,
    ], $changes));
}

it('plans the candidate user and Linux asset for a real provisional assignment without admitting a fleet member', function (): void {
    FleetFixtures::node('cli-gateway', [RoleName::Gateway])->update(['wireguard_ip' => '10.44.0.1']);
    $node = FleetFixtures::node('cli-reservation', [RoleName::Database], managed: false, status: LifecycleStatus::Provisioning);
    $node->roles()->update(['status' => LifecycleStatus::Provisioning]);
    $node->update(['architecture' => null]);
    $before = $node->fresh()->getAttributes();
    $rolesBefore = $node->roles()->get()->toArray();
    $settingsBefore = Setting::query()->get()->toArray();
    $plan = app(CliConvergePlan::class);
    $input = $plan->input($node, cliEnrollmentCandidate($node));

    expect($input['user'])->toBe('next-orbit')
        ->and($input['skip_reason'])->toBeNull()->and($input['plan_error'])->toBeNull()
        ->and($input['release']['version'])->toBe('0.4681.0')
        ->and($input['release']['url'])->toEndWith('/orbit-0.4681.0-linux-x86_64')
        ->and($input['release']['sha256'])->toBe(str_repeat('c', 64))
        ->and(json_decode($input['profile'], true)['gateways']['gateway']['url'])->toBe('https://10.44.0.1')
        ->and($plan->input($node)['skip_reason'])->toBe('inactive')
        ->and(app(FleetRolloutMembership::class)->includes($node))->toBeFalse()
        ->and($node->fresh()->getAttributes())->toBe($before)
        ->and($node->roles()->get()->toArray())->toBe($rolesBefore)
        ->and(Setting::query()->get()->toArray())->toBe($settingsBefore)
        ->and(Operation::query()->count())->toBe(0);
});

it('reads candidate CLI data from the canonical render scope and restores the stored input afterward', function (): void {
    FleetFixtures::node('scope-cli-gateway', [RoleName::Gateway]);
    $node = FleetFixtures::node('scope-cli-target', [RoleName::Database]);
    $plan = app(CliConvergePlan::class);
    $before = $plan->input($node);
    $context = app(RouteRenderContext::class);
    $context->renderEnrollment(cliEnrollmentCandidate($node), function () use ($node, $plan, $context): void {
        expect($plan->input($node)['user'])->toBe('next-orbit');
        $context->renderActual(fn () => expect($plan->input($node)['user'])->toBe('orbit'));
        expect($plan->input($node)['user'])->toBe('next-orbit');
    });
    expect($plan->input($node))->toBe($before);
});

it('keeps the canonical roleless exclusion without requiring a fabricated assignment', function (): void {
    $node = FleetFixtures::node('roleless-cli', managed: false, status: LifecycleStatus::Provisioning);
    $input = app(CliConvergePlan::class)->input($node, cliEnrollmentCandidate($node));
    expect($input)->toBe(['user' => 'next-orbit', 'release' => null, 'profile' => '', 'skip_reason' => 'roleless', 'plan_error' => null])
        ->and($node->roles()->count())->toBe(0);
});

it('selects only the supported desired Linux asset when observation is pending or unsupported', function (?string $architecture, ?string $error): void {
    FleetFixtures::node('arch-cli-gateway', [RoleName::Gateway]);
    $node = FleetFixtures::node('arch-cli-target', [RoleName::Database]);
    $input = app(CliConvergePlan::class)->input($node, cliEnrollmentCandidate($node, ['architecture' => $architecture]));
    expect($input['plan_error'])->toBe($error);
    expect($input['release'] === null)->toBe($error !== null);
})->with(['unobserved desired Linux' => [null, null], 'explicit unsupported Linux' => ['arm64', 'cli.architecture_unsupported']]);

it('preserves platform and Gateway exclusions for prospective capture', function (string $platform, RoleName $role, string $reason): void {
    $node = FleetFixtures::node('excluded-cli-target', [$role]);
    $input = app(CliConvergePlan::class)->input($node, cliEnrollmentCandidate($node, ['platform' => $platform]));
    expect($input['skip_reason'])->toBe($reason)->and($input['release'])->toBeNull();
})->with(['Mac' => ['macos', RoleName::Database, 'platform'], 'Gateway' => ['linux', RoleName::Gateway, 'gateway']]);

it('keeps the remembered Gateway out even while its stored Node is provisioning', function (): void {
    $node = FleetFixtures::node('serving-cli-target', [RoleName::Database]);
    app(GatewayServingHost::class)->remember($node);
    $node->update(['status' => LifecycleStatus::Provisioning]);
    expect(app(CliConvergePlan::class)->input($node, cliEnrollmentCandidate($node))['skip_reason'])->toBe('gateway');
});

it('keeps task VMs excluded from prospective CLI publication', function (): void {
    $node = FleetFixtures::taskVm();
    expect(app(CliConvergePlan::class)->input($node, cliEnrollmentCandidate($node))['skip_reason'])->toBe('sandbox');
});

it('rejects a candidate for another Node before capturing a release', function (): void {
    $node = FleetFixtures::node('cli-candidate-owner', [RoleName::Database]);
    expect(fn () => app(CliConvergePlan::class)->input($node, cliEnrollmentCandidate($node, ['nodeId' => $node->id + 1])))
        ->toThrow(ResourceOperationException::class, 'The CLI candidate must name the actual reserved Node.');
});

it('keeps unpublished CLI releases skipped for prospective targets', function (): void {
    app()->instance(DesiredFleetState::class, new DesiredFleetState(new FakeReleaseHistory,
        new FakeCliReleaseCatalog(available: false), new Repository(new ArrayStore), app(AgentDelivery::class)));
    $node = FleetFixtures::node('pending-cli-target', [RoleName::Database]);
    $input = app(CliConvergePlan::class)->input($node, cliEnrollmentCandidate($node));
    expect($input['skip_reason'])->toBe('release_missing')->and($input['release'])->toBeNull()
        ->and($input['plan_error'])->toBeNull()->and($input['user'])->toBe('next-orbit');
});

it('does not apply one candidate to another stored participant', function (): void {
    $node = FleetFixtures::node('cli-scope-owner', [RoleName::Database]);
    $other = FleetFixtures::node('cli-stored-participant', [RoleName::Database], status: LifecycleStatus::Provisioning);
    app(RouteRenderContext::class)->renderEnrollment(cliEnrollmentCandidate($node), function () use ($other): void {
        $plan = app(CliConvergePlan::class);
        expect($plan->input($other)['user'])->toBe('orbit')
            ->and($plan->exclusion($other))->toBe('inactive');
    });
});
