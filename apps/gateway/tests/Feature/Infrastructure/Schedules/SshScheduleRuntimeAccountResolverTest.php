<?php

declare(strict_types=1);

use App\Actions\NodeFacts\RecordNodeFactAction;
use App\Domain\AgentView\AgentStateView;
use App\Domain\NodeFacts\NodeFactKind;
use App\Domain\Schedules\ScheduleErrorCode;
use App\Domain\Schedules\ScheduleOperationException;
use App\Domain\Schedules\ScheduleRuntimeAccount;
use App\Infrastructure\AgentCommands\AgentCommandRefused;
use App\Infrastructure\AgentCommands\AgentCommandRunner;
use App\Infrastructure\AgentView\CacheAgentStateView;
use App\Infrastructure\Schedules\SshScheduleRuntimeAccountResolver;
use App\Models\Node;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Cache;

uses(LazilyRefreshDatabase::class);

function schedule_account_node(string $name = 'apps', string $ip = '10.44.0.5'): Node
{
    return Node::withoutEvents(fn (): Node => Node::query()->create([
        'name' => $name, 'status' => 'active', 'platform' => 'linux', 'wireguard_ip' => $ip,
        'public_ssh_host' => $ip, 'user' => 'orbit', 'ssh_host_fingerprint' => 'SHA256:fingerprint',
    ]));
}

function schedule_account_entry(array $overrides = []): array
{
    return $overrides + ['user' => 'app', 'uid' => 1001, 'group' => 'app-group', 'home' => '/home/app', 'shell' => '/bin/bash'];
}

function schedule_account_fact(Node $node, array $accounts): void
{
    app(RecordNodeFactAction::class)->execute(
        $node, NodeFactKind::Accounts, '', CarbonImmutable::now()->subMonth(), ['accounts' => $accounts],
    );
}

function schedule_account_view(Node $node, int $eventAge = 0, int $subscriberAge = 0): void
{
    $view = new CacheAgentStateView(Cache::store('array'));
    app()->instance(AgentStateView::class, $view);
    $view->putSubscriber(true, true, 1);
    test()->travel($subscriberAge)->seconds();
    $view->putNode($node->id, [], 'absent', 1, CacheAgentStateView::now() - $eventAge, null);
}

beforeEach(function (): void {
    $this->freezeTime();
});

describe('Schedule runtime account resolution', function (): void {
    it('uses the matching stored account without an agent call while the agent is online', function (): void {
        $node = schedule_account_node();
        schedule_account_view($node);
        schedule_account_fact($node, [schedule_account_entry(['user' => 'orbit']), schedule_account_entry()]);
        $agents = Mockery::mock(AgentCommandRunner::class);
        $agents->shouldNotReceive('run');
        app()->instance(AgentCommandRunner::class, $agents);

        expect(app(SshScheduleRuntimeAccountResolver::class)->resolve($node, 'app'))
            ->toEqual(new ScheduleRuntimeAccount('app', 'app-group', '/home/app', '/bin/bash'));
    });

    it('refuses an account missing from the fact without an agent call', function (array $accounts): void {
        $node = schedule_account_node();
        schedule_account_view($node);
        schedule_account_fact($node, $accounts);
        $agents = Mockery::mock(AgentCommandRunner::class);
        $agents->shouldNotReceive('run');
        app()->instance(AgentCommandRunner::class, $agents);

        expect(fn () => app(SshScheduleRuntimeAccountResolver::class)->resolve($node, 'app'))
            ->toThrow(function (ScheduleOperationException $exception): void {
                expect($exception->error)->toBe(ScheduleErrorCode::TargetUnavailable);
                expect($exception->step)->toBe('resolve-account');
            });
    })->with([
        'another account' => [[['user' => 'orbit', 'uid' => 1000, 'group' => 'orbit', 'home' => '/home/orbit', 'shell' => '/bin/bash']]],
        'empty inventory' => [[]],
    ]);

    it('looks up the account when only another Node has an accounts fact', function (): void {
        $node = schedule_account_node();
        schedule_account_view($node);
        schedule_account_fact(schedule_account_node('other', '10.44.0.6'), [schedule_account_entry(['home' => '/wrong'])]);
        $agents = Mockery::mock(AgentCommandRunner::class);
        $agents->shouldReceive('run')->once()->with($node, 'account.lookup', ['user' => 'app'])
            ->andReturn(schedule_account_entry());
        app()->instance(AgentCommandRunner::class, $agents);

        expect(app(SshScheduleRuntimeAccountResolver::class)->resolve($node, 'app'))
            ->toEqual(new ScheduleRuntimeAccount('app', 'app-group', '/home/app', '/bin/bash'));
    });

    it('looks up the account when the stored fact is not current by agent liveness', function (int $eventAge, int $subscriberAge): void {
        $node = schedule_account_node();
        schedule_account_view($node, $eventAge, $subscriberAge);
        schedule_account_fact($node, []);
        $agents = Mockery::mock(AgentCommandRunner::class);
        $agents->shouldReceive('run')->once()->with($node, 'account.lookup', ['user' => 'app'])
            ->andReturn(schedule_account_entry());
        app()->instance(AgentCommandRunner::class, $agents);

        expect(app(SshScheduleRuntimeAccountResolver::class)->resolve($node, 'app'))
            ->toEqual(new ScheduleRuntimeAccount('app', 'app-group', '/home/app', '/bin/bash'));
    })->with(['offline agent' => [16, 0], 'stopped subscriber' => [0, 31]]);

    it('refuses an invalid stored account without an agent call', function (array $overrides): void {
        $node = schedule_account_node();
        schedule_account_view($node);
        $entry = schedule_account_entry($overrides);
        schedule_account_fact($node, [$entry]);
        $agents = Mockery::mock(AgentCommandRunner::class);
        $agents->shouldNotReceive('run');
        app()->instance(AgentCommandRunner::class, $agents);

        expect(fn () => app(SshScheduleRuntimeAccountResolver::class)->resolve($node, $entry['user']))
            ->toThrow(function (ScheduleOperationException $exception): void {
                expect($exception->error)->toBe(ScheduleErrorCode::TargetUnavailable);
                expect($exception->step)->toBe('resolve-account');
            });
    })->with([
        'unsupported user' => [['user' => 'app$']],
        'invalid group' => [['group' => "app\nroot"]],
        'missing group' => [['group' => null]],
        'invalid home' => [['home' => 123]],
        'missing home' => [['home' => null]],
        'invalid shell' => [['shell' => []]],
        'missing shell' => [['shell' => null]],
    ]);

    it('keeps the lookup error codes and step', function (Throwable $failure, ScheduleErrorCode $error): void {
        $node = schedule_account_node();
        $agents = Mockery::mock(AgentCommandRunner::class);
        $agents->shouldReceive('run')->once()->with($node, 'account.lookup', ['user' => 'app'])->andThrow($failure);
        app()->instance(AgentCommandRunner::class, $agents);

        expect(fn () => app(SshScheduleRuntimeAccountResolver::class)->resolve($node, 'app'))
            ->toThrow(function (ScheduleOperationException $exception) use ($error): void {
                expect($exception->error)->toBe($error);
                expect($exception->step)->toBe('resolve-account');
            });
    })->with([
        'refused lookup' => [new AgentCommandRefused('account.lookup', 'account.not_found', 'No account.'), ScheduleErrorCode::TargetUnavailable],
        'unreachable agent' => [new RuntimeException('Connection failed.'), ScheduleErrorCode::NodeUnreachable],
    ]);
});
