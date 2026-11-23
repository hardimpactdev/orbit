<?php

declare(strict_types=1);

use App\Actions\Firewall\ListManagedFirewallRulesAction;
use App\Domain\Extensions\ExtensionStore;
use App\Domain\Nodes\RoleName;
use App\Domain\Operations\OperationStatus;
use App\Domain\Shared\LifecycleStatus;
use App\Domain\Shared\ResourceOperationException;
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

    $operation = app(AdmitAiRouterTaskGuardAction::class)->execute(triggeredBy: 'test');
    $intent = $operation->input['intent'];
    $secrets = app(AiRouterSecrets::class);

    expect($operation->kind)->toBe('ai_router.task_guard')->and($operation->node_id)->toBe($services->id)
        ->and($intent['origin'])->toBe('http://10.44.0.3:8318')->and($intent['state'])->toBe('present')
        ->and($operation->input['execution_secrets'])->toBe([
            'task_key' => ['vault' => 'orbit-control-inputs', 'key' => 'ai-router-task-key-node-'.$services->id],
            'anchor_key' => ['vault' => 'orbit-control-inputs', 'key' => 'ai-router-anchor-key-node-'.$services->id],
        ])
        // The intent holds neither key: the unit loads both as systemd credentials on the Node.
        ->and(json_encode($operation->input))->not->toContain($secrets->task())->not->toContain($secrets->anchor('http://10.44.0.3:8317'))
        ->and($secrets->task())->toMatch('/\A[a-f0-9]{64}\z/')->not->toBe($secrets->anchor('http://10.44.0.3:8317'))
        ->and($intent['caddyfile'])
        ->toContain("bind 10.44.0.3\n")
        ->toContain("remote_ip 10.44.0.128/25\n")
        ->toContain("method POST\n")
        ->toContain('expression {http.request.orig_uri.path} in ["/v1/responses", "/v1/chat/completions", "/v1/messages", "/v1/messages/count_tokens"]'
            .' && ({http.request.orig_uri.query} == "" || ({http.request.orig_uri.query} == "beta=true"'
            .' && {http.request.orig_uri.path} in ["/v1/messages", "/v1/messages/count_tokens"]))')
        ->toContain('header Authorization "Bearer {file./run/credentials/orbit-ai-router-guard.service/task.key}"')
        ->toContain("expression ({http.request.orig_uri.path}.matches('^/v1beta/models/[A-Za-z0-9]+(?:[._-][A-Za-z0-9]+)*:streamGenerateContent\$')"
            .' && {http.request.orig_uri.query} == "alt=sse")'
            ." || ({http.request.orig_uri.path}.matches('^/v1beta/models/[A-Za-z0-9]+(?:[._-][A-Za-z0-9]+)*:(?:generateContent|countTokens)\$')"
            .' && {http.request.orig_uri.query} == "")')
        ->toContain('header X-Goog-Api-Key "{file./run/credentials/orbit-ai-router-guard.service/task.key}"')
        ->toContain("handle @gemini {\n\t\timport router\n")
        ->toContain("reverse_proxy 127.0.0.1:8317 {\n")
        ->toContain('header_up Authorization "Bearer {file./run/credentials/orbit-ai-router-guard.service/anchor.key}"')
        ->toContain("@source remote_ip 10.44.0.128/25\n")
        ->toContain("respond 401\n")
        ->toContain("respond 403\n")
        // The trust first, then the deny and its allow, each at the top: the allow ends above the deny, both above the trust.
        ->and($intent['firewall'])->toBe(['desired' => [
            ['ufw', 'allow', 'in', 'on', 'orbit', 'to', '10.44.0.3', 'comment', 'orbit:wireguard-members'],
            ['ufw', 'insert', '1', 'deny', 'in', 'on', 'orbit', 'proto', 'tcp', 'from', 'any', 'to', '10.44.0.3', 'port', '8318', 'comment', 'orbit:ai-router-task-guard-deny'],
            ['ufw', 'insert', '1', 'allow', 'in', 'on', 'orbit', 'proto', 'tcp', 'from', '10.44.0.128/25', 'to', '10.44.0.3', 'port', '8318', 'comment', 'orbit:ai-router-task-guard'],
        ], 'remove' => []]);
});

it('keeps one task key and one anchor key across admissions', function (): void {
    guardRouter();
    $secrets = app(AiRouterSecrets::class);
    $first = [$secrets->task(), $secrets->anchor('http://10.44.0.3:8317')];

    expect([$secrets->task(), $secrets->anchor('http://10.44.0.3:8317')])->toBe($first);
});

it('lists the UFW pair in the desired firewall of the router Node only, once a run applied the guard and until one removes it', function (): void {
    $services = guardRouter();
    $other = guardNode('beast', '10.44.0.7');
    $names = static fn (Node $node): array => array_column(app(ListManagedFirewallRulesAction::class)->execute($node), 'name');
    $succeeded = static fn (Operation $operation): bool => $operation->forceFill(['status' => OperationStatus::Succeeded, 'lock_key' => null])->save();

    expect($names($services))->not->toContain('orbit:ai-router-task-guard');
    $succeeded(app(AdmitAiRouterTaskGuardAction::class)->execute(triggeredBy: 'test'));
    expect($names($services))->toContain('orbit:ai-router-task-guard', 'orbit:ai-router-task-guard-deny')
        ->and($names($other))->not->toContain('orbit:ai-router-task-guard')
        ->and(collect(app(ListManagedFirewallRulesAction::class)->execute($services))->firstWhere('name', 'orbit:ai-router-task-guard'))
        ->toMatchArray(['action' => 'allow', 'source' => '10.44.0.128/25', 'destination' => '10.44.0.3', 'port' => '8318']);

    $removal = app(AdmitAiRouterTaskGuardAction::class)->execute(remove: true, triggeredBy: 'test');
    expect($removal->input['intent']['state'])->toBe('absent')
        ->and($removal->input['intent']['firewall']['remove'])->toBe([
            ['ufw', '--force', 'delete', 'allow', 'in', 'on', 'orbit', 'proto', 'tcp', 'from', '10.44.0.128/25', 'to', '10.44.0.3', 'port', '8318', 'comment', 'orbit:ai-router-task-guard'],
            ['ufw', '--force', 'delete', 'deny', 'in', 'on', 'orbit', 'proto', 'tcp', 'from', 'any', 'to', '10.44.0.3', 'port', '8318', 'comment', 'orbit:ai-router-task-guard-deny'],
        ]);
    $succeeded($removal);
    expect($names($services))->not->toContain('orbit:ai-router-task-guard');
});

it('refuses the guard while the ai-router is off', function (): void {
    expect(fn () => app(AdmitAiRouterTaskGuardAction::class)->execute(triggeredBy: 'test'))->toThrow(ResourceOperationException::class, 'disabled');
});

it('refuses a second guard run while one holds the guard', function (): void {
    $services = guardRouter();

    $first = app(AdmitAiRouterTaskGuardAction::class)->execute(triggeredBy: 'test');

    expect($first->lock_key)->toBe('ai_router_guard:'.$services->id)
        ->and(fn () => app(AdmitAiRouterTaskGuardAction::class)->execute(triggeredBy: 'test'))
        ->toThrow(ResourceOperationException::class, 'Another Operation owns this target.');
});

/** An operator Node with access to the active Gateway, which calls the API from its WireGuard address. */
function guardApiCaller(): Node
{
    $gateway = guardNode('gateway', '10.44.0.2');
    $gateway->roles()->create(['role' => RoleName::Gateway, 'status' => LifecycleStatus::Active]);
    $caller = guardNode('laptop', '10.44.0.9');
    $caller->accessibleNodes()->attach($gateway);
    app(ExtensionStore::class)->set('ai-router', true);

    return $caller;
}

it('applies or removes the guard through the API, as the artisan command does', function (): void {
    $services = guardRouter();
    $caller = guardApiCaller();
    $call = fn (array $body, string $key) => $this->withServerVariables(['REMOTE_ADDR' => $caller->wireguard_ip])
        ->postJson('/api/v1/ai-router/task-guard', $body, ['Idempotency-Key' => $key]);

    $applied = $call([], 'apply-key')->assertStatus(202)->assertJsonPath('data.kind', 'ai_router.task_guard')->assertJsonPath('data.status', 'queued');
    $operation = Operation::query()->findOrFail($applied->json('data.id'));
    expect($operation->node_id)->toBe($services->id)->and($operation->triggered_by)->toBe('ai-router:task-guard')
        ->and($operation->input['intent']['state'])->toBe('present')
        ->and($operation->input['guard_digest'])->toStartWith('sha256:')
        ->and($applied->getContent())->not->toContain(app(AiRouterSecrets::class)->task());

    // The same key replays the run; another key meets the guard's lock.
    $call([], 'apply-key')->assertStatus(202)->assertJsonPath('data.id', $operation->id);
    $call(['remove' => true], 'remove-key')->assertStatus(409)->assertJsonPath('error.code', 'operation.target_busy');

    $operation->forceFill(['status' => OperationStatus::Succeeded, 'lock_key' => null])->save();
    $removal = $call(['remove' => true], 'remove-key')->assertStatus(202);
    expect(Operation::query()->findOrFail($removal->json('data.id'))->input['intent']['state'])->toBe('absent');
});

it('refuses a guard API call that is not a boolean removal, from a caller without Gateway access, or while the ai-router is off', function (): void {
    guardRouter();
    $caller = guardApiCaller();
    $call = fn (Node $from, array $body) => $this->withServerVariables(['REMOTE_ADDR' => $from->wireguard_ip])->postJson('/api/v1/ai-router/task-guard', $body);

    $call($caller, ['remove' => 'yes'])->assertStatus(422)->assertJsonPath('error.code', 'validation.failed');
    $call(guardNode('stranger', '10.44.0.11'), [])->assertForbidden()->assertJsonPath('error.code', 'node_access.required');
    app(AiRouterState::class)->disable();
    $call($caller, [])->assertStatus(409)->assertJsonPath('error.code', 'ai_router.disabled');
    app(ExtensionStore::class)->set('ai-router', false);
    $call($caller, [])->assertStatus(409)->assertJsonPath('error.code', 'extension.disabled');

    expect(Operation::query()->count())->toBe(0);
});
