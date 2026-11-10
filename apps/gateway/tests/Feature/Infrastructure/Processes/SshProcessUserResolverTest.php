<?php

declare(strict_types=1);

use App\Actions\NodeFacts\RecordNodeFactAction;
use App\Domain\AgentView\AgentStateView;
use App\Domain\NodeFacts\NodeFactKind;
use App\Domain\Shared\ResourceOperationException;
use App\Infrastructure\AgentCommands\AgentCommandRunner;
use App\Infrastructure\AgentView\CacheAgentStateView;
use App\Infrastructure\Processes\SshProcessUserResolver;
use App\Models\Node;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Cache;

uses(LazilyRefreshDatabase::class);

function process_account_node(string $name = 'apps', string $ip = '10.44.0.5'): Node
{
    return Node::withoutEvents(fn (): Node => Node::query()->create([
        'name' => $name, 'status' => 'active', 'platform' => 'linux', 'wireguard_ip' => $ip,
        'public_ssh_host' => $ip, 'user' => 'orbit', 'ssh_host_fingerprint' => 'SHA256:fingerprint',
    ]));
}

function process_account_entry(array $overrides = []): array
{
    return $overrides + ['user' => 'app', 'uid' => 1001, 'group' => 'app-group', 'home' => '/srv/app', 'shell' => '/bin/bash'];
}

function process_account_fact(Node $node, array $accounts): void
{
    app(RecordNodeFactAction::class)->execute(
        $node, NodeFactKind::Accounts, '', CarbonImmutable::now()->subMonth(), ['accounts' => $accounts],
    );
}

function process_account_view(Node $node, int $eventAge = 0, int $subscriberAge = 0): void
{
    $view = new CacheAgentStateView(Cache::store('array'));
    app()->instance(AgentStateView::class, $view);
    $view->putSubscriber(true, true, 1);
    test()->travel($subscriberAge)->seconds();
    $view->putNode($node->id, [], 'absent', 1, CacheAgentStateView::now() - $eventAge, null);
}

beforeEach(function (): void {
    $this->freezeTime();
    $agents = Mockery::mock(AgentCommandRunner::class);
    $agents->shouldNotReceive('run');
    app()->instance(AgentCommandRunner::class, $agents);
});

describe('Process user resolution', function (): void {
    it('uses the matching stored account home without an agent call while the agent is online', function (): void {
        $node = process_account_node();
        process_account_view($node);
        process_account_fact($node, [process_account_entry(['user' => 'orbit', 'home' => '/wrong']), process_account_entry()]);

        expect(app(SshProcessUserResolver::class)->home($node, 'app'))->toBe('/srv/app');
    });

    it('refuses a user not listed in the accounts fact', function (array $accounts): void {
        $node = process_account_node();
        process_account_view($node);
        process_account_fact($node, $accounts);

        expect(fn () => app(SshProcessUserResolver::class)->home($node, 'app'))
            ->toThrow(function (ResourceOperationException $exception): void {
                expect($exception->errorCode)->toBe('process.user_unavailable');
            });
    })->with([
        'another user' => [[process_account_entry(['user' => 'orbit'])]],
        'empty inventory' => [[]],
        'malformed entry' => [[null]],
    ]);

    it('refuses a missing fact even when another Node has the account', function (): void {
        $node = process_account_node();
        process_account_view($node);
        process_account_fact(process_account_node('other', '10.44.0.6'), [process_account_entry(['home' => '/wrong'])]);

        expect(fn () => app(SshProcessUserResolver::class)->home($node, 'app'))
            ->toThrow(function (ResourceOperationException $exception): void {
                expect($exception->errorCode)->toBe('process.user_unavailable');
            });
    });

    it('refuses a fact that is not current by agent liveness', function (int $eventAge, int $subscriberAge): void {
        $node = process_account_node();
        process_account_view($node, $eventAge, $subscriberAge);
        process_account_fact($node, [process_account_entry()]);

        expect(fn () => app(SshProcessUserResolver::class)->home($node, 'app'))
            ->toThrow(function (ResourceOperationException $exception): void {
                expect($exception->errorCode)->toBe('process.user_unavailable');
            });
    })->with(['offline agent' => [16, 0], 'stopped subscriber' => [0, 31]]);

    it('refuses a fact when the agent has not been seen', function (): void {
        $node = process_account_node();
        process_account_fact($node, [process_account_entry()]);
        app()->instance(AgentStateView::class, new CacheAgentStateView(Cache::store('array')));

        expect(fn () => app(SshProcessUserResolver::class)->home($node, 'app'))
            ->toThrow(function (ResourceOperationException $exception): void {
                expect($exception->errorCode)->toBe('process.user_unavailable');
            });
    });

    it('refuses an invalid stored account or root uid', function (array $overrides): void {
        $node = process_account_node();
        process_account_view($node);
        process_account_fact($node, [process_account_entry($overrides)]);

        expect(fn () => app(SshProcessUserResolver::class)->home($node, 'app'))
            ->toThrow(function (ResourceOperationException $exception): void {
                expect($exception->errorCode)->toBe('process.user_unavailable');
            });
    })->with([
        'missing uid' => [['uid' => null]],
        'string uid' => [['uid' => '1001']],
        'root uid' => [['uid' => 0]],
        'missing home' => [['home' => null]],
        'non-string home' => [['home' => 123]],
        'relative home' => [['home' => 'srv/app']],
        'control character' => [['home' => "/srv/app\n"]],
        'oversized home' => [['home' => '/'.str_repeat('a', 4096)]],
        'empty segment' => [['home' => '/srv//app']],
        'dot segment' => [['home' => '/srv/./app']],
        'parent segment' => [['home' => '/srv/../app']],
    ]);

    it('keeps invalid and root names as process.user_invalid', function (string $user): void {
        expect(fn () => app(SshProcessUserResolver::class)->home(process_account_node(), $user))
            ->toThrow(function (ResourceOperationException $exception): void {
                expect($exception->errorCode)->toBe('process.user_invalid');
            });
    })->with(['root', 'app$', '', 'App', "app\nroot"]);

    it('refuses a Node with no WireGuard address', function (?string $host): void {
        $node = process_account_node();
        process_account_view($node);
        process_account_fact($node, [process_account_entry()]);
        $node->wireguard_ip = $host;

        expect(fn () => app(SshProcessUserResolver::class)->home($node, 'app'))
            ->toThrow(function (ResourceOperationException $exception): void {
                expect($exception->errorCode)->toBe('process.user_unavailable');
            });
    })->with(['missing address' => [null], 'empty address' => ['']]);
});
