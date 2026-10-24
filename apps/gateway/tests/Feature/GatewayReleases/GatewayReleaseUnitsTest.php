<?php

declare(strict_types=1);

use App\Domain\GatewayReleases\GatewayReleaseLayout;
use App\Domain\Nodes\NodeProvisioningException;
use App\Domain\Nodes\RoleName;
use App\Domain\Shared\LifecycleStatus;
use App\Infrastructure\AgentCommands\AgentRunnerFailure;
use App\Infrastructure\GatewayReleases\GatewayReleaseCommands;
use App\Infrastructure\GatewayReleases\NativeGatewayReleaseUnitConverger;
use App\Infrastructure\GatewayReleases\SystemdGatewayReleaseUnitStarter;
use App\Models\Node;
use Tests\Support\FakeAgentCommandRunner;

beforeEach(function (): void {
    $node = Node::query()->create(['name' => 'gateway', 'public_ssh_host' => '192.0.2.2', 'status' => LifecycleStatus::Active]);
    $node->roles()->create(['role' => RoleName::Gateway, 'status' => LifecycleStatus::Active]);
    $this->agents = new FakeAgentCommandRunner;
    $this->commands = new GatewayReleaseCommands($this->agents);
});

describe(NativeGatewayReleaseUnitConverger::class, function (): void {
    it('sends only the stable application identity and configured retention policy', function (): void {
        config(['orbit.gateway_checkout' => base_path(), 'orbit.home' => '/home/orbit/.orbit',
            'retention.journal_max_mb' => 128, 'retention.journal_keep_free_mb' => 2048,
            'retention.journal_days' => 3, 'retention.journal_runtime_max_mb' => 16,
            'retention.log_rotate_mb' => 8, 'retention.log_rotate_keep' => 2]);
        $this->agents->answer('gateway.converge-release-units', ['changed' => false]);

        new NativeGatewayReleaseUnitConverger($this->commands)->converge();

        expect($this->agents->calls)->toBe([['node' => 'gateway', 'command' => 'gateway.converge-release-units', 'input' => [
            'application' => base_path(), 'orbit_home' => '/home/orbit/.orbit', 'user' => 'orbit',
            'retention' => ['journal_max_mb' => 128, 'journal_keep_free_mb' => 2048, 'journal_days' => 3,
                'journal_runtime_max_mb' => 16, 'log_rotate_mb' => 8, 'log_rotate_keep' => 2],
        ]]]);
    });

    it('installs units during bootstrap without admitting release execution', function (): void {
        Node::query()->update(['status' => LifecycleStatus::Provisioning]);
        Node::query()->firstOrFail()->roles()->update(['status' => LifecycleStatus::Provisioning]);
        $this->agents->answer('gateway.converge-release-units', ['changed' => true]);
        new NativeGatewayReleaseUnitConverger($this->commands)->converge();
        expect(release_failure(fn () => $this->commands->startRun(7))->errorCode)->toBe('gateway.release_unit_failed');
        expect($this->agents->commands())->toBe(['gateway.converge-release-units']);
    });

    it('maps the native installation failure and preserves its stage', function (): void {
        $this->agents->refuse('gateway.converge-release-units', 'gateway.release_units_install_failed', ['stage' => 'gateway-retention-packages']);
        try {
            new NativeGatewayReleaseUnitConverger($this->commands)->converge();
            test()->fail('Expected convergence to fail.');
        } catch (NodeProvisioningException $error) {
            expect($error->errorCode)->toBe('gateway.release_units_install_failed')->and($error->step)->toBe('gateway-retention-packages');
        }
    });

    it('refuses a malformed convergence result', function (): void {
        $this->agents->answer('gateway.converge-release-units', ['changed' => 'yes']);
        expect(fn () => new NativeGatewayReleaseUnitConverger($this->commands)->converge())->toThrow(NodeProvisioningException::class);
    });
});

describe(SystemdGatewayReleaseUnitStarter::class, function (): void {
    it('requests one record and waits for its native state to become active', function (): void {
        $states = ['inactive', 'activating'];
        $sleeps = [];
        $this->agents->answer('gateway.start-release-run', ['changed' => true])
            ->answer('gateway.inspect-release-run', static function () use (&$states): array {
                return ['state' => array_shift($states)];
            });
        $starter = new SystemdGatewayReleaseUnitStarter($this->commands, sleep: static function (int $ms) use (&$sleeps): void {
            $sleeps[] = $ms;
        });

        $starter->start(7, static fn (): bool => false);

        expect($this->agents->calls)->toBe([
            ['node' => 'gateway', 'command' => 'gateway.start-release-run', 'input' => ['record' => 7]],
            ['node' => 'gateway', 'command' => 'gateway.inspect-release-run', 'input' => ['record' => 7]],
            ['node' => 'gateway', 'command' => 'gateway.inspect-release-run', 'input' => ['record' => 7]],
        ]);
        expect($sleeps)->toBe([500]);
    });

    it('accepts a short-lived unit once its record was claimed', function (string $state): void {
        $this->agents->answer('gateway.start-release-run', ['changed' => true])->answer('gateway.inspect-release-run', ['state' => $state]);
        new SystemdGatewayReleaseUnitStarter($this->commands, sleep: static fn () => test()->fail('A claimed record must not wait.'))->start(7, static fn (): bool => true);
        expect($this->agents->commands())->toBe(['gateway.start-release-run', 'gateway.inspect-release-run']);
    })->with(['inactive', 'failed', 'unknown']);

    it('checks the record claim once more after the last observation', function (): void {
        $claims = 0;
        $this->agents->answer('gateway.start-release-run', ['changed' => true])->answer('gateway.inspect-release-run', ['state' => 'inactive']);
        new SystemdGatewayReleaseUnitStarter($this->commands, attempts: 1, sleep: static function (): void {})->start(7, static function () use (&$claims): bool {
            return ++$claims === 2;
        });
        expect($claims)->toBe(2);
    });

    it('does not take a queued start as proof that the unit runs', function (string $state, int $observations): void {
        $this->agents->answer('gateway.start-release-run', ['changed' => true])->answer('gateway.inspect-release-run', ['state' => $state]);
        $failure = release_failure(fn () => new SystemdGatewayReleaseUnitStarter($this->commands, attempts: 3, sleep: static function (): void {})->start(7, static fn (): bool => false));
        expect($failure->errorCode)->toBe('gateway.release_unit_failed')->and($failure->status)->toBe(503);
        expect($this->agents->calls)->toHaveCount(1 + $observations);
    })->with([['failed', 1], ['inactive', 3]]);

    it('maps native refusals and transport loss to a service-unavailable error', function (bool $lost): void {
        if ($lost) {
            $this->agents->answer('gateway.start-release-run', static fn (): array => throw new AgentRunnerFailure('gateway.start-release-run', AgentRunnerFailure::Transport, 'lost'));
        } else {
            $this->agents->refuse('gateway.start-release-run', 'gateway.release_unit_failed', ['record' => 7]);
        }
        $failure = release_failure(fn () => new SystemdGatewayReleaseUnitStarter($this->commands)->start(7, static fn (): bool => false));
        expect($failure->errorCode)->toBe('gateway.release_unit_failed')->and($failure->status)->toBe(503);
        expect($this->agents->commands())->toBe(['gateway.start-release-run']);
    })->with([false, true]);

    it('reads activity without requesting a start', function (string $state, bool $active): void {
        $this->agents->answer('gateway.inspect-release-run', ['state' => $state]);
        expect(new SystemdGatewayReleaseUnitStarter($this->commands)->isActive(7))->toBe($active);
        expect($this->agents->commands())->toBe(['gateway.inspect-release-run']);
    })->with([['active', true], ['activating', true], ['reloading', true], ['deactivating', true], ['inactive', false], ['failed', false]]);

    it('rejects a malformed observation', function (): void {
        $this->agents->answer('gateway.inspect-release-run', ['state' => 'invalid']);
        expect(release_failure(fn () => new SystemdGatewayReleaseUnitStarter($this->commands)->isActive(7))->status)->toBe(503);
    });
});

it('sends the finalize release identity and maps unavailable schema status to a retained hold', function (): void {
    $layout = new GatewayReleaseLayout('/home/orbit/orbit/apps/gateway');
    $this->agents->answer('gateway.check-release-migrations', ['complete' => true]);
    $this->commands->checkMigrations($layout, '0123456789ab', '/home/orbit/.orbit/gateway-release-step.lock');
    expect($this->agents->calls)->toBe([['node' => 'gateway', 'command' => 'gateway.check-release-migrations', 'input' => [
        'checkout' => $layout->applicationPath, 'release' => '0123456789ab', 'step_lock' => '/home/orbit/.orbit/gateway-release-step.lock',
    ]]]);
    $this->agents->answer('gateway.check-release-migrations', ['complete' => false]);
    $failure = release_failure(fn () => $this->commands->checkMigrations($layout, '0123456789ab', '/home/orbit/.orbit/gateway-release-step.lock'));
    expect($failure->errorCode)->toBe('gateway.release_schema_pending')->and($failure->step)->toBe('finalize')->and($failure->status)->toBe(409);
});
