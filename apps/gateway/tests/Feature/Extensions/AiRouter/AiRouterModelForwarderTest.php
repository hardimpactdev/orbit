<?php

declare(strict_types=1);

use App\Actions\Firewall\ListManagedFirewallRulesAction;
use App\Domain\Nodes\RoleName;
use App\Domain\Operations\OperationStatus;
use App\Domain\Shared\LifecycleStatus;
use App\Domain\Shared\ResourceOperationException;
use App\Domain\TaskVms\TaskVmHost;
use App\Domain\TaskVms\TaskVmSettings;
use App\Infrastructure\Swamp\SwampCommandStep;
use App\Infrastructure\Swamp\SwampDispatch;
use App\Models\Node;
use App\Models\Operation;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Process;
use Orbit\Extensions\AiRouter\Actions\AdmitAiRouterModelForwarderAction;
use Orbit\Extensions\AiRouter\Actions\AdmitAiRouterTaskGuardAction;
use Orbit\Extensions\AiRouter\Domain\AiRouterModelForwarder;
use Orbit\Extensions\AiRouter\Domain\AiRouterSecrets;
use Orbit\Extensions\AiRouter\Domain\AiRouterState;
use Orbit\Extensions\AiRouter\Domain\AiRouterTaskGuard;
use Orbit\Extensions\AiRouter\Domain\AiRouterTaskGuardProjection;

uses(LazilyRefreshDatabase::class);

function forwarderNode(string $name, string $address): Node
{
    return Node::withoutEvents(fn (): Node => Node::query()->create(['name' => $name, 'status' => 'active', 'platform' => 'linux',
        'architecture' => 'x86_64', 'public_ssh_host' => $address, 'wireguard_ip' => $address, 'user' => 'orbit',
        'ssh_host_fingerprint' => 'SHA256:fixture', 'ssh_host_key_type' => 'ssh-ed25519', 'ssh_host_key' => 'fixture-public-key']));
}

/** The router on `services` (10.44.0.3), the swamp Node, and task VMs in 10.44.0.128/25. */
function forwarderRouter(): Node
{
    $services = forwarderNode('services', '10.44.0.3');
    forwarderNode('swamp', '10.44.0.15')->roles()->create(['role' => RoleName::Swamp, 'status' => LifecycleStatus::Active]);
    app()->instance(TaskVmSettings::class, new TaskVmSettings(true, null, '10.44.0.128/25',
        [new TaskVmHost(9, 'orbit-tasks', 'orbittask0', '10.252.7.0/24', 6, 4, '8GiB', '20GiB')],
        '/pi', str_repeat('a', 64), ['gpt-6']));
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

function forwarderSucceeded(Operation $operation): Operation
{
    $operation->forceFill(['status' => OperationStatus::Succeeded, 'lock_key' => null])->save();

    return $operation;
}

/** @return list<string> */
function forwarderRuleNames(Node $node): array
{
    return array_column(app(ListManagedFirewallRulesAction::class)->execute($node), 'name');
}

it('admits the Node at the guard and records a forwarder with no key, as one run on the guard lock', function (): void {
    $services = forwarderRouter();
    $beast = forwarderNode('beast', '10.44.0.7');

    $operation = app(AdmitAiRouterModelForwarderAction::class)->execute($beast, 'orbitc0', '10.251.33.1', triggeredBy: 'test');
    $intent = $operation->input['intent'];
    $secrets = app(AiRouterSecrets::class);

    expect($operation->kind)->toBe('ai_router.model_forwarder')
        ->and($operation->node_id)->toBe($services->id)->and($operation->target_id)->toBe($beast->id)
        ->and($operation->lock_key)->toBe('ai_router_guard:'.$services->id)
        // The guard part: applied, with the Node's WireGuard address next to the task VM range, and its allow above the deny.
        ->and($intent['state'])->toBe('present')->and($intent['origin'])->toBe('http://10.44.0.3:8318')
        ->and($intent['caddyfile'])->toContain("remote_ip 10.44.0.128/25 10.44.0.7\n")
        ->and($intent['firewall']['desired'])->toContain(
            ['ufw', 'insert', '1', 'allow', 'in', 'on', 'orbit', 'proto', 'tcp', 'from', '10.44.0.7', 'to', '10.44.0.3', 'port', '8318', 'comment', 'orbit:ai-router-task-guard-node-'.$beast->id])
        ->and($intent['firewall']['remove'])->toBe([])
        ->and($operation->input['execution_secrets'])->toBe([
            'task_key' => ['vault' => 'orbit-control-inputs', 'key' => 'ai-router-task-key-node-'.$services->id],
            'anchor_key' => ['vault' => 'orbit-control-inputs', 'key' => 'ai-router-anchor-key-node-'.$services->id],
        ])
        // The forwarder part: the bridge address, proxied unchanged to the guard, on the bridge only.
        ->and($intent['forwarder'])->toMatchArray(['state' => 'present', 'bridge' => 'orbitc0', 'address' => '10.251.33.1',
            'origin' => 'http://10.251.33.1:8319', 'guard_origin' => 'http://10.44.0.3:8318',
            'directory' => '/etc/orbit/ai-router-forwarder', 'unit' => 'orbit-ai-router-forwarder.service'])
        ->and($intent['forwarder']['caddyfile'])->toContain("bind 10.251.33.1\n")->toContain("reverse_proxy http://10.44.0.3:8318\n")
        ->not->toContain('header_up')->not->toContain('credentials')
        ->and($intent['forwarder']['firewall'])->toBe(['desired' => [
            ['ufw', 'allow', 'in', 'on', 'orbitc0', 'proto', 'tcp', 'from', 'any', 'to', '10.251.33.1', 'port', '8319', 'comment', 'orbit:ai-router-forwarder'],
        ], 'remove' => []])
        // No key anywhere in the intent; the forwarder gets none.
        ->and(json_encode($operation->input))->not->toContain($secrets->task())->not->toContain($secrets->anchor('http://10.44.0.3:8317'));

    expect(fn () => app(AdmitAiRouterModelForwarderAction::class)->execute(forwarderNode('shark', '10.44.0.8'), 'orbitc0', '10.251.34.1', triggeredBy: 'test'))
        ->toThrow(ResourceOperationException::class, 'Another Operation owns this target.');
});

it('keeps a forwarder Node in every later guard run and firewall until a run removes it', function (): void {
    $services = forwarderRouter();
    $beast = forwarderNode('beast', '10.44.0.7');
    $allow = 'orbit:ai-router-task-guard-node-'.$beast->id;

    // A queued or failed run admits nothing.
    $first = app(AdmitAiRouterModelForwarderAction::class)->execute($beast, 'orbitc0', '10.251.33.1', triggeredBy: 'test');
    $first->forceFill(['status' => OperationStatus::Failed, 'lock_key' => null])->save();
    expect(forwarderRuleNames($services))->not->toContain($allow)->and(forwarderRuleNames($beast))->not->toContain('orbit:ai-router-forwarder');

    forwarderSucceeded(app(AdmitAiRouterModelForwarderAction::class)->execute($beast, 'orbitc0', '10.251.33.1', triggeredBy: 'test'));
    // A succeeded forwarder run applied the guard too.
    expect(forwarderRuleNames($services))->toContain('orbit:ai-router-task-guard', $allow, 'orbit:ai-router-task-guard-deny')
        ->and(forwarderRuleNames($beast))->toContain('orbit:ai-router-forwarder')
        ->and(collect(app(ListManagedFirewallRulesAction::class)->execute($beast))->firstWhere('name', 'orbit:ai-router-forwarder'))
        ->toMatchArray(['action' => 'allow', 'destination' => '10.251.33.1', 'port' => '8319']);

    $guard = forwarderSucceeded(app(AdmitAiRouterTaskGuardAction::class)->execute(triggeredBy: 'test'));
    expect($guard->input['intent']['caddyfile'])->toContain("remote_ip 10.44.0.128/25 10.44.0.7\n")
        ->and(forwarderRuleNames($services))->toContain($allow);

    $removal = app(AdmitAiRouterModelForwarderAction::class)->execute($beast, null, null, remove: true, triggeredBy: 'test');
    $intent = $removal->input['intent'];
    expect($intent['state'])->toBe('present')
        ->and($intent['caddyfile'])->toContain("remote_ip 10.44.0.128/25\n")
        ->and($intent['firewall']['remove'])->toBe([
            ['ufw', '--force', 'delete', 'allow', 'in', 'on', 'orbit', 'proto', 'tcp', 'from', '10.44.0.7', 'to', '10.44.0.3', 'port', '8318', 'comment', $allow],
        ])
        // The bridge and address of the last run come back for the removal.
        ->and($intent['forwarder'])->toMatchArray(['state' => 'absent', 'bridge' => 'orbitc0', 'address' => '10.251.33.1'])
        ->and($intent['forwarder']['firewall'])->toBe(['desired' => [], 'remove' => [
            ['ufw', '--force', 'delete', 'allow', 'in', 'on', 'orbitc0', 'proto', 'tcp', 'from', 'any', 'to', '10.251.33.1', 'port', '8319', 'comment', 'orbit:ai-router-forwarder'],
        ]]);
    forwarderSucceeded($removal);
    expect(forwarderRuleNames($services))->not->toContain($allow)->toContain('orbit:ai-router-task-guard')
        ->and(forwarderRuleNames($beast))->not->toContain('orbit:ai-router-forwarder')
        ->and(app(AdmitAiRouterTaskGuardAction::class)->execute(triggeredBy: 'test')->input['intent']['caddyfile'])
        ->toContain("remote_ip 10.44.0.128/25\n");
});

it('removes each forwarder allow with the guard, and admits the forwarders again on the next guard run', function (): void {
    forwarderRouter();
    $beast = forwarderNode('beast', '10.44.0.7');
    forwarderSucceeded(app(AdmitAiRouterModelForwarderAction::class)->execute($beast, 'orbitc0', '10.251.33.1', triggeredBy: 'test'));

    $removal = forwarderSucceeded(app(AdmitAiRouterTaskGuardAction::class)->execute(remove: true, triggeredBy: 'test'));

    expect($removal->input['intent']['firewall']['remove'])->toContain(
        ['ufw', '--force', 'delete', 'allow', 'in', 'on', 'orbit', 'proto', 'tcp', 'from', '10.44.0.7', 'to', '10.44.0.3', 'port', '8318', 'comment', 'orbit:ai-router-task-guard-node-'.$beast->id])
        ->and(app(AdmitAiRouterTaskGuardAction::class)->execute(triggeredBy: 'test')->input['intent']['caddyfile'])
        ->toContain("remote_ip 10.44.0.128/25 10.44.0.7\n");
});

it('refuses a forwarder on the router Node, the swamp Node, an invalid Node or bridge, and a removal with no run', function (): void {
    $services = forwarderRouter();
    $admit = app(AdmitAiRouterModelForwarderAction::class);
    $beast = forwarderNode('beast', '10.44.0.7');
    $refusal = static function (callable $call): string {
        try {
            $call();
        } catch (ResourceOperationException $exception) {
            return $exception->errorCode;
        }

        return 'none';
    };

    expect($refusal(fn () => $admit->execute($services, 'orbitc0', '10.251.33.1')))->toBe('ai_router.forwarder_refused')
        ->and($refusal(fn () => $admit->execute(Node::query()->where('name', 'swamp')->firstOrFail(), 'orbitc0', '10.251.33.1')))->toBe('ai_router.forwarder_refused')
        ->and($refusal(fn () => $admit->execute(forwarderNode('mac', '10.44.0.9')->forceFill(['platform' => 'macos']), 'orbitc0', '10.251.33.1')))->toBe('ai_router.node_invalid')
        ->and($refusal(fn () => $admit->execute(forwarderNode('nowg', '10.44.0.10')->forceFill(['wireguard_ip' => null]), 'orbitc0', '10.251.33.1')))->toBe('ai_router.node_invalid')
        ->and($refusal(fn () => $admit->execute($beast, 'orbitc0; rm', '10.251.33.1')))->toBe('validation.failed')
        ->and($refusal(fn () => $admit->execute($beast, 'orbitc0', '10.251.33.0/24')))->toBe('validation.failed')
        ->and($refusal(fn () => $admit->execute($beast, null, null)))->toBe('validation.failed')
        ->and($refusal(fn () => $admit->execute($beast, null, null, remove: true)))->toBe('resource.not_found')
        ->and(Operation::query()->where('kind', AiRouterModelForwarder::Kind)->count())->toBe(0);

    app(AiRouterState::class)->disable();
    expect($refusal(fn () => $admit->execute($beast, 'orbitc0', '10.251.33.1')))->toBe('ai_router.disabled');
});

/**
 * Runs the forwarder and the guard the Gateway renders on real Caddy, chained on loopback: loopback stands in for the agent Node's
 * WireGuard address at the guard, so only the forwarder Node's own source entry admits it; the task VM range does not hold loopback.
 */
it('passes the placeholder through the forwarder unchanged, so the guard alone decides', function (): void {
    if (! is_executable(trim((string) shell_exec('command -v caddy')))) {
        $this->markTestSkipped('Caddy is not installed.');
    }
    $port = static function (): int {
        $socket = stream_socket_server('tcp://127.0.0.1:0');
        $name = (string) stream_socket_get_name($socket, false);
        fclose($socket);

        return (int) substr($name, strrpos($name, ':') + 1);
    };
    [$upstream, $guardPort, $forwarderPort] = [$port(), $port(), $port()];
    $services = forwarderNode('services', '10.44.0.3');
    $beast = forwarderNode('beast', '10.44.0.7');
    app()->instance(TaskVmSettings::class, new TaskVmSettings(true, null, '10.44.0.128/25',
        [new TaskVmHost(9, 'orbit-tasks', 'orbittask0', '10.252.7.0/24', 6, 4, '8GiB', '20GiB')],
        '/pi', str_repeat('a', 64), ['gpt-6']));
    app(AiRouterState::class)->enable($services->id, 'cache', "http://10.44.0.3:{$upstream}", 'management', 'read', 'control');
    $directory = sys_get_temp_dir().'/orbit-forwarder-'.bin2hex(random_bytes(4));
    mkdir($directory);
    file_put_contents("{$directory}/task.key", 'task-key');
    file_put_contents("{$directory}/anchor.key", 'anchor-key');
    $guard = static fn (array $forwarders): string => strtr(app(AiRouterTaskGuard::class)->caddyfile($services, $forwarders), [
        'bind 10.44.0.3' => 'bind 127.0.0.1', ' 10.44.0.7' => ' 127.0.0.1/32',
        AiRouterTaskGuard::Credentials => $directory, 'http://:'.AiRouterTaskGuard::Port => "http://:{$guardPort}",
    ]);
    $forwarder = strtr(app(AiRouterModelForwarder::class)->caddyfile('10.251.33.1', 'http://10.44.0.3:8318'), [
        'bind 10.251.33.1' => 'bind 127.0.0.1', 'http://10.44.0.3:8318' => "http://127.0.0.1:{$guardPort}",
        'http://:'.AiRouterModelForwarder::Port => "http://:{$forwarderPort}",
    ]);
    $echo = "http://:{$upstream} {\n\tbind 127.0.0.1\n\trespond \"auth={header.Authorization} uri={uri}\"\n}\n";
    $run = static function (string $caddyfile) use ($directory, $forwarder, $echo, $forwarderPort) {
        // One Caddy per configuration: the snippets each open with a global options block.
        $files = [];
        foreach (['guard' => $caddyfile, 'forwarder' => $forwarder, 'echo' => $echo] as $name => $content) {
            file_put_contents($files[] = "{$directory}/{$name}.Caddyfile", $content);
        }
        $caddies = array_map(static fn (string $file) => Process::start(['caddy', 'run', '--adapter', 'caddyfile', '--config', $file]), $files);
        retry(50, static fn () => Http::post("http://127.0.0.1:{$forwarderPort}/"), 100);

        return $caddies;
    };
    $send = static fn (string $uri, ?string $key): array => [($response = Http::withHeaders(array_filter(['Authorization' => $key === null ? null : "Bearer {$key}"]))
        ->post("http://127.0.0.1:{$forwarderPort}{$uri}"))->status(), $response->body()];
    $stop = static function (array $caddies): void {
        foreach ($caddies as $caddy) {
            $caddy->stop(2);
        }
    };

    try {
        $caddies = $run($guard([$beast]));
        try {
            expect($send('/v1/messages?beta=true', 'task-key'))->toBe([200, 'auth=Bearer anchor-key uri=/v1/messages?beta=true'])
                ->and($send('/v1/responses', 'task-key'))->toBe([200, 'auth=Bearer anchor-key uri=/v1/responses'])
                ->and($send('/v1/responses', null)[0])->toBe(403)
                ->and($send('/v1/responses', 'anchor-key')[0])->toBe(403)
                ->and($send('/v0/management/config', 'task-key')[0])->toBe(403);
        } finally {
            $stop($caddies);
        }

        // Without the forwarder Node among the guard's sources, the same request is refused.
        $caddies = $run($guard([]));
        try {
            expect($send('/v1/responses', 'task-key')[0])->toBe(403);
        } finally {
            $stop($caddies);
        }
    } finally {
        Process::run(['rm', '-rf', $directory]);
    }
});

it('records the forwarder origin as the result of a succeeded run, and the guard origin for a guard run', function (): void {
    forwarderRouter();
    $beast = forwarderNode('beast', '10.44.0.7');
    $project = static function (Operation $operation): Operation {
        $operation->forceFill(['status' => OperationStatus::Running, 'state' => [...$operation->state, 'swamp_run_id' => 'fixture-run']])->save();

        return app(AiRouterTaskGuardProjection::class)->apply($operation, ['run_id' => 'fixture-run', 'status' => 'succeeded',
            'started_at' => now()->toIso8601String(), 'finished_at' => now()->toIso8601String(), 'result' => ['steps' => []]]);
    };

    expect($project(app(AdmitAiRouterModelForwarderAction::class)->execute($beast, 'orbitc0', '10.251.33.1', triggeredBy: 'test'))->result)
        ->toMatchArray(['origin' => 'http://10.251.33.1:8319'])
        ->and($project(app(AdmitAiRouterTaskGuardAction::class)->execute(triggeredBy: 'test'))->result)
        ->toMatchArray(['origin' => 'http://10.44.0.3:8318']);
});
