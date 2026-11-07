<?php

declare(strict_types=1);

use App\Actions\Firewall\ListManagedFirewallRulesAction;
use App\Domain\Nodes\RoleName;
use App\Domain\Shared\LifecycleStatus;
use App\Domain\Shared\ResourceOperationException;
use App\Domain\TaskVms\TaskVmHost;
use App\Domain\TaskVms\TaskVmSettings;
use App\Infrastructure\Swamp\SwampCommandStep;
use App\Infrastructure\Swamp\SwampDispatch;
use App\Models\Node;
use App\Models\Operation;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Orbit\Extensions\AiRouter\Actions\AdmitAiRouterTaskGuardAction;
use Orbit\Extensions\AiRouter\Domain\AiRouterSecrets;
use Orbit\Extensions\AiRouter\Domain\AiRouterState;

uses(LazilyRefreshDatabase::class);

function guardNode(string $name, string $address): Node
{
    return Node::withoutEvents(fn (): Node => Node::query()->create(['name' => $name, 'status' => 'active', 'platform' => 'linux',
        'architecture' => 'x86_64', 'public_ssh_host' => $address, 'wireguard_ip' => $address, 'user' => 'orbit',
        'ssh_host_fingerprint' => 'SHA256:fixture', 'ssh_host_key_type' => 'ssh-ed25519', 'ssh_host_key' => 'fixture-public-key']));
}

/** The router on `services` (10.44.0.3), with task VMs in 10.44.0.128/25. */
function guardRouter(): Node
{
    $services = guardNode('services', '10.44.0.3');
    guardNode('swamp', '10.44.0.15')->roles()->create(['role' => RoleName::Swamp, 'status' => LifecycleStatus::Active]);
    app()->instance(TaskVmSettings::class, new TaskVmSettings(true, null, '10.44.0.128/25',
        [new TaskVmHost(9, 'orbit-tasks', 'orbittask0', '10.252.7.0/24', 6, 4, '8GiB', '20GiB')],
        'http://10.44.0.3:8317', '/pi', str_repeat('a', 64), ['gpt-6']));
    // A stand-in for the vault write: it returns the reference, as the Swamp copy does.
    app()->instance(SwampCommandStep::class, new class
    {
        /** @return array{vault: string, key: string} */
        public function executionCopy(string $vault, string $key, string $value): array
        {
            return ['vault' => $vault, 'key' => $key];
        }
    });
    app()->instance(SwampDispatch::class, new class
    {
        public function start(Operation $operation): void {}
    });
    app(AiRouterState::class)->enable($services->id, 'services-valkey', 'http://10.44.0.3:8317', 'management', 'read', 'control');

    return $services;
}

it('records the guard intent without a key: the Caddyfile, the UFW pair and vault copies of both keys', function (): void {
    $services = guardRouter();

    $operation = app(AdmitAiRouterTaskGuardAction::class)->execute('test');
    $intent = $operation->input['intent'];
    $secrets = app(AiRouterSecrets::class);

    expect($operation->kind)->toBe('ai_router.task_guard')->and($operation->node_id)->toBe($services->id)
        ->and($intent['origin'])->toBe('http://10.44.0.3:8318')
        ->and($operation->input['execution_secrets'])->toBe([
            'task_key' => ['vault' => 'orbit-control-inputs', 'key' => 'ai-router-task-key-node-'.$services->id],
            'anchor_key' => ['vault' => 'orbit-control-inputs', 'key' => 'ai-router-anchor-key-node-'.$services->id],
        ])
        // The intent holds neither key: Caddy reads both from files on the Node.
        ->and(json_encode($operation->input))->not->toContain($secrets->task())->not->toContain($secrets->anchor('http://10.44.0.3:8317'))
        ->and($secrets->task())->toMatch('/\A[a-f0-9]{64}\z/')->not->toBe($secrets->anchor('http://10.44.0.3:8317'))
        ->and($intent['caddyfile'])
        ->toContain("bind 10.44.0.3\n")
        ->toContain("remote_ip 10.44.0.128/25\n")
        ->toContain("method POST\n")
        ->toContain("path /v1/responses /v1/chat/completions /v1/messages\n")
        ->toContain('header Authorization "Bearer {file./etc/orbit/ai-router-guard/task.key}"')
        ->toContain("reverse_proxy 127.0.0.1:8317 {\n")
        ->toContain('header_up Authorization "Bearer {file./etc/orbit/ai-router-guard/anchor.key}"')
        ->toContain("respond 403\n")
        // Deny first, then allow, each at the top: the allow ends above the deny.
        ->and($intent['firewall'])->toBe([
            ['ufw', 'insert', '1', 'deny', 'in', 'on', 'orbit', 'proto', 'tcp', 'from', 'any', 'to', '10.44.0.3', 'port', '8318', 'comment', 'orbit:ai-router-task-guard-deny'],
            ['ufw', 'insert', '1', 'allow', 'in', 'on', 'orbit', 'proto', 'tcp', 'from', '10.44.0.128/25', 'to', '10.44.0.3', 'port', '8318', 'comment', 'orbit:ai-router-task-guard'],
        ]);
});

it('keeps one task key and one anchor key across admissions', function (): void {
    guardRouter();
    $secrets = app(AiRouterSecrets::class);
    $first = [$secrets->task(), $secrets->anchor('http://10.44.0.3:8317')];

    expect([$secrets->task(), $secrets->anchor('http://10.44.0.3:8317')])->toBe($first);
});

it('lists the UFW pair in the desired firewall of the router Node only', function (): void {
    $services = guardRouter();
    $other = guardNode('beast', '10.44.0.7');
    $names = static fn (Node $node): array => array_column(app(ListManagedFirewallRulesAction::class)->execute($node), 'name');

    expect($names($services))->toContain('orbit:ai-router-task-guard', 'orbit:ai-router-task-guard-deny')
        ->and($names($other))->not->toContain('orbit:ai-router-task-guard')
        ->and(collect(app(ListManagedFirewallRulesAction::class)->execute($services))->firstWhere('name', 'orbit:ai-router-task-guard'))
        ->toMatchArray(['action' => 'allow', 'source' => '10.44.0.128/25', 'destination' => '10.44.0.3', 'port' => '8318']);
});

it('refuses the guard while the ai-router is off', function (): void {
    expect(fn () => app(AdmitAiRouterTaskGuardAction::class)->execute('test'))->toThrow(ResourceOperationException::class, 'disabled');
});
