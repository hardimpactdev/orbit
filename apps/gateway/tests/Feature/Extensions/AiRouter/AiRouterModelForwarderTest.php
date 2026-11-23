<?php

declare(strict_types=1);

use App\Actions\Firewall\ListManagedFirewallRulesAction;
use App\Domain\Nodes\RoleName;
use App\Domain\Operations\OperationStatus;
use App\Domain\Shared\LifecycleStatus;
use App\Domain\Shared\ResourceOperationException;
use App\Infrastructure\Swamp\SwampCommandStep;
use App\Infrastructure\Swamp\SwampDispatch;
use App\Models\Node;
use App\Models\Operation;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Process\InvokedProcess;
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
    // The forwarder's Orbit routes go to the active Gateway's WireGuard address.
    forwarderNode('gateway', '10.6.0.2')->roles()->create(['role' => RoleName::Gateway, 'status' => LifecycleStatus::Active]);
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
    // A forwarder run needs the guard in place.
    forwarderSucceeded(app(AdmitAiRouterTaskGuardAction::class)->execute(triggeredBy: 'test'));

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

/**
 * The rendered forwarder on loopback: its bind, its ports and its upstreams. Its Orbit routes go to `$gateway` over plain HTTP: the TLS
 * transport names the Node agent's root certificate, which a test runner has not, and Caddy refuses to start without it.
 *
 * @param  array<string, string>  $map
 */
function forwarderOnLoopback(string $rendered, array $map, string $gateway): string
{
    $loopback = preg_replace('#reverse_proxy https://10\.6\.0\.2 \{\n(\t+header_up Host 10\.6\.0\.2\n)\t+transport http \{\n\t+tls_trust_pool file [^\n]+\n\t+\}\n#',
        "reverse_proxy {$gateway} {\n\$1", strtr($rendered, $map));
    if (! is_string($loopback) || str_contains($loopback, 'https://')) {
        throw new LogicException('The forwarder still names a TLS upstream.');
    }

    return $loopback;
}

/**
 * Waits until each port answers, at most 3 seconds, with each request bounded to a second; a Caddy that exited fails at once with its output.
 *
 * @param  array<string, InvokedProcess>  $caddies
 * @param  list<int>  $ports
 */
function forwarderCaddiesAnswer(array $caddies, array $ports): void
{
    $deadline = microtime(true) + 3;
    while (true) {
        foreach ($caddies as $name => $caddy) {
            if (! $caddy->running()) {
                throw new RuntimeException("The {$name} Caddy exited: ".$caddy->errorOutput());
            }
        }
        try {
            foreach ($ports as $port) {
                Http::timeout(1)->connectTimeout(1)->post("http://127.0.0.1:{$port}/")->throwIf(static fn ($response): bool => $response->status() === 502);
            }

            return;
        } catch (ConnectionException|RequestException) {
        }
        if (microtime(true) > $deadline) {
            throw new RuntimeException('The Caddy processes did not answer within 3 seconds.');
        }
        usleep(25_000);
    }
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
        ->and($intent['forwarder']['caddyfile'])->toContain("bind 10.251.33.1\n")->toContain("\thandle {\n\t\treverse_proxy http://10.44.0.3:8318\n\t}\n")
        ->not->toContain('credentials')
        // The Orbit routes of a task copy: each exact path to its fixed Gateway path, trusting only the Orbit root certificate.
        ->toContain("\thandle /orbit/v1/copy/whoami {\n\t\trewrite * /api/v1/copy/whoami\n\t\treverse_proxy https://10.6.0.2 {\n\t\t\theader_up Host 10.6.0.2\n"
            ."\t\t\ttransport http {\n\t\t\t\ttls_trust_pool file /etc/orbit/agent/ca.pem\n")
        ->toContain("\thandle /orbit/v1/copy/pull-request {\n\t\trewrite * /api/v1/copy/pull-request\n")
        ->toContain("\thandle /orbit/v1/copy/handoff {\n\t\trewrite * /api/v1/copy/handoff\n")
        ->and(substr_count($intent['forwarder']['caddyfile'], 'reverse_proxy https://'))->toBe(3)
        ->and($intent['forwarder']['firewall'])->toBe(['desired' => [
            ['ufw', 'allow', 'in', 'on', 'orbitc0', 'proto', 'tcp', 'from', 'any', 'to', '10.251.33.1', 'port', '8319', 'comment', 'orbit:ai-router-forwarder'],
        ], 'remove' => []])
        // Its own table: forwarded packets to the guard dropped ahead of Docker and UFW, and only its user may connect from the Node.
        ->and($intent['forwarder'])->toMatchArray(['user' => 'orbit-ai-router-forwarder', 'table' => 'orbit_ai_router_forwarder',
            'table_unit' => 'orbit-ai-router-forwarder-table.service'])
        ->and($intent['forwarder']['ruleset'])
        ->toContain("type filter hook forward priority filter - 10; policy accept;\n\t\tip daddr 10.44.0.3 tcp dport 8318 counter drop\n")
        ->toContain("type filter hook output priority filter - 10; policy accept;\n\t\tip daddr 10.44.0.3 tcp dport 8318 meta skuid != \"orbit-ai-router-forwarder\" counter reject with tcp reset\n")
        // Only the bridge reaches the forwarder: a process on the Node would reach it over `lo` and be relayed as the forwarder's user.
        ->toContain("type filter hook input priority filter - 10; policy accept;\n\t\tip daddr 10.251.33.1 tcp dport 8319 iifname != \"orbitc0\" counter reject with tcp reset\n")
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
    // The guard keeps going without the Node's allow; `tasks/firewall.yml` removes the allow the rules no longer name.
    expect($intent['state'])->toBe('present')
        ->and($intent['caddyfile'])->toContain("remote_ip 10.44.0.128/25\n")
        ->and(collect($intent['firewall']['desired'])->map(fn (array $command): string => end($command)))->not->toContain($allow)
        ->and($intent['firewall']['remove'])->toBe([])
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

it('removes the guard with its pair, refuses a forwarder until it is back, and removes one without bringing it back', function (): void {
    forwarderRouter();
    $beast = forwarderNode('beast', '10.44.0.7');
    forwarderSucceeded(app(AdmitAiRouterModelForwarderAction::class)->execute($beast, 'orbitc0', '10.251.33.1', triggeredBy: 'test'));

    $removal = forwarderSucceeded(app(AdmitAiRouterTaskGuardAction::class)->execute(remove: true, triggeredBy: 'test'));

    // The pair only: `tasks/firewall.yml` takes every `-node-<id>` allow with it, named or stray.
    expect($removal->input['intent']['firewall']['remove'])->toBe([
        ['ufw', '--force', 'delete', 'allow', 'in', 'on', 'orbit', 'proto', 'tcp', 'from', '10.44.0.128/25', 'to', '10.44.0.3', 'port', '8318', 'comment', 'orbit:ai-router-task-guard'],
        ['ufw', '--force', 'delete', 'deny', 'in', 'on', 'orbit', 'proto', 'tcp', 'from', 'any', 'to', '10.44.0.3', 'port', '8318', 'comment', 'orbit:ai-router-task-guard-deny'],
    ])
        // With the guard off, no forwarder run brings it back.
        ->and(fn () => app(AdmitAiRouterModelForwarderAction::class)->execute($beast, 'orbitc0', '10.251.33.1', triggeredBy: 'test'))
        ->toThrow(ResourceOperationException::class, 'The guard is not in place');

    // A removal still runs, only its forwarder half: the guard stays off, and the next guard run admits no removed Node.
    $offRemoval = forwarderSucceeded(app(AdmitAiRouterModelForwarderAction::class)->execute($beast, null, null, remove: true, triggeredBy: 'test'));
    expect($offRemoval->input['intent']['state'])->toBe('absent')
        ->and($offRemoval->input['intent']['forwarder']['state'])->toBe('absent')
        ->and(app(AiRouterTaskGuard::class)->applied(Node::query()->where('name', 'services')->firstOrFail()))->toBeFalse()
        ->and(app(AdmitAiRouterTaskGuardAction::class)->execute(triggeredBy: 'test')->input['intent']['caddyfile'])
        ->toContain("remote_ip 10.44.0.128/25\n");
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
        ->and($refusal(function () use ($admit) {
            $hub = forwarderNode('vpn', '10.44.0.1');
            $hub->roles()->create(['role' => RoleName::Vpn, 'status' => LifecycleStatus::Active]);
            $admit->execute($hub, 'orbitc0', '10.251.33.1');
        }))->toBe('ai_router.forwarder_refused')
        ->and($refusal(fn () => $admit->execute(forwarderNode('mac', '10.44.0.9')->forceFill(['platform' => 'macos']), 'orbitc0', '10.251.33.1')))->toBe('ai_router.node_invalid')
        ->and($refusal(fn () => $admit->execute(forwarderNode('nowg', '10.44.0.10')->forceFill(['wireguard_ip' => null]), 'orbitc0', '10.251.33.1')))->toBe('ai_router.node_invalid')
        ->and($refusal(fn () => $admit->execute($beast, 'orbitc0; rm', '10.251.33.1')))->toBe('validation.failed')
        ->and($refusal(fn () => $admit->execute($beast, 'orbitc0', '10.251.33.0/24')))->toBe('validation.failed')
        ->and($refusal(fn () => $admit->execute($beast, null, null)))->toBe('validation.failed')
        ->and($refusal(fn () => $admit->execute($beast, null, null, remove: true)))->toBe('resource.not_found')
        ->and(Operation::query()->where('kind', AiRouterModelForwarder::Kind)->count())->toBe(0);

    // Before the first guard run, and after its removal, no forwarder run applies the guard.
    forwarderSucceeded(app(AdmitAiRouterTaskGuardAction::class)->execute(remove: true, triggeredBy: 'test'));
    expect($refusal(fn () => $admit->execute($beast, 'orbitc0', '10.251.33.1')))->toBe('ai_router.guard_absent');

    forwarderSucceeded(app(AdmitAiRouterTaskGuardAction::class)->execute(triggeredBy: 'test'));
    Node::query()->where('name', 'gateway')->update(['wireguard_ip' => null]);
    expect($refusal(fn () => $admit->execute($beast, 'orbitc0', '10.251.33.1')))->toBe('ai_router.gateway_unaddressed');

    app(AiRouterState::class)->disable();
    expect($refusal(fn () => $admit->execute($beast, 'orbitc0', '10.251.33.1')))->toBe('ai_router.disabled')
        ->and($refusal(fn () => $admit->execute($beast, null, null, remove: true)))->toBe('ai_router.disabled');
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
    app(AiRouterState::class)->enable($services->id, 'cache', "http://10.44.0.3:{$upstream}", 'management', 'read', 'control');
    $directory = sys_get_temp_dir().'/orbit-forwarder-'.bin2hex(random_bytes(4));
    mkdir($directory);
    file_put_contents("{$directory}/task.key", 'task-key');
    file_put_contents("{$directory}/anchor.key", 'anchor-key');
    $guard = static fn (array $forwarders): string => strtr(app(AiRouterTaskGuard::class)->caddyfile($services, $forwarders), [
        'bind 10.44.0.3' => 'bind 127.0.0.1', ' 10.44.0.7' => ' 127.0.0.1/32',
        AiRouterTaskGuard::Credentials => $directory, 'http://:'.AiRouterTaskGuard::Port => "http://:{$guardPort}",
    ]);
    $forwarder = forwarderOnLoopback(app(AiRouterModelForwarder::class)->caddyfile('10.251.33.1', 'http://10.44.0.3:8318', '10.6.0.2'), [
        'bind 10.251.33.1' => 'bind 127.0.0.1', 'http://10.44.0.3:8318' => "http://127.0.0.1:{$guardPort}",
        'http://:'.AiRouterModelForwarder::Port => "http://:{$forwarderPort}",
    ], "http://127.0.0.1:{$upstream}");
    // The echo server needs its own admin listener too; Caddy otherwise binds the shared default :2019.
    $echo = "{\n\tadmin 127.0.0.1:0\n\tpersist_config off\n}\nhttp://:{$upstream} {\n\tbind 127.0.0.1\n\trespond \"auth={header.Authorization} uri={uri}\"\n}\n";
    $run = static function (string $caddyfile) use ($directory, $forwarder, $echo, $upstream, $guardPort, $forwarderPort) {
        // One Caddy per configuration: the snippets each open with a global options block.
        $files = [];
        foreach (['guard' => $caddyfile, 'forwarder' => $forwarder, 'echo' => $echo] as $name => $content) {
            file_put_contents($files[] = "{$directory}/{$name}.Caddyfile", $content);
        }
        $caddies = array_map(static fn (string $file) => Process::start(['caddy', 'run', '--adapter', 'caddyfile', '--config', $file]),
            array_combine(['guard', 'forwarder', 'echo'], $files));
        // A listening forwarder can return 502 while either downstream Caddy is still starting.
        forwarderCaddiesAnswer($caddies, [$upstream, $guardPort, $forwarderPort]);

        return $caddies;
    };
    $send = static fn (string $uri, ?string $key): array => [($response = Http::timeout(1)->connectTimeout(1)
        ->withHeaders(array_filter(['Authorization' => $key === null ? null : "Bearer {$key}"]))
        ->post("http://127.0.0.1:{$forwarderPort}{$uri}"))->status(), $response->body()];
    $stop = static function (array $caddies): void {
        foreach ($caddies as $caddy) {
            $caddy->stop(0);
        }
    };

    try {
        $caddies = $run($guard([$beast]));
        try {
            expect($send('/v1/messages?beta=true', 'task-key'))->toBe([200, 'auth=Bearer anchor-key uri=/v1/messages?beta=true'])
                ->and($send('/v1/responses', 'task-key'))->toBe([200, 'auth=Bearer anchor-key uri=/v1/responses'])
                // The guard admits the forwarder Node, so a refused request gets 401: what a forwarder run's end check expects.
                ->and($send('/v1/responses', null)[0])->toBe(401)
                ->and($send('/v1/responses', 'anchor-key')[0])->toBe(401)
                ->and($send('/v0/management/config', 'task-key')[0])->toBe(401);
        } finally {
            $stop($caddies);
        }

        // Without the forwarder Node among the guard's sources, the same request is refused, and so is a request with no key.
        $caddies = $run($guard([]));
        try {
            expect($send('/v1/responses', 'task-key')[0])->toBe(403)->and($send('/v1/responses', null)[0])->toBe(403);
        } finally {
            $stop($caddies);
        }
    } finally {
        Process::run(['rm', '-rf', $directory]);
    }
});

it('sends exactly the two Orbit routes to their fixed Gateway paths, and every other path to the guard, on real Caddy', function (): void {
    if (! is_executable(trim((string) shell_exec('command -v caddy')))) {
        $this->markTestSkipped('Caddy is not installed.');
    }
    $port = static function (): int {
        $socket = stream_socket_server('tcp://127.0.0.1:0');
        $name = (string) stream_socket_get_name($socket, false);
        fclose($socket);

        return (int) substr($name, strrpos($name, ':') + 1);
    };
    [$gatewayPort, $guardPort, $forwarderPort] = [$port(), $port(), $port()];
    $directory = sys_get_temp_dir().'/orbit-forwarder-routes-'.bin2hex(random_bytes(4));
    mkdir($directory);
    $rendered = app(AiRouterModelForwarder::class)->caddyfile('10.251.33.1', 'http://10.44.0.3:8318', '10.6.0.2');
    // The whole rendered file, its TLS transport included, adapts on real Caddy; nothing runs for this part.
    file_put_contents("{$directory}/rendered.Caddyfile", $rendered);
    $adapted = Process::timeout(3)->run(['caddy', 'adapt', '--adapter', 'caddyfile', '--config', "{$directory}/rendered.Caddyfile"]);
    // The routing runs on loopback over plain HTTP: the TLS transport is the one part the stand-in replaces.
    $forwarder = forwarderOnLoopback($rendered, [
        'bind 10.251.33.1' => 'bind 127.0.0.1', 'http://10.44.0.3:8318' => "http://127.0.0.1:{$guardPort}",
        'http://:'.AiRouterModelForwarder::Port => "http://:{$forwarderPort}",
    ], "http://127.0.0.1:{$gatewayPort}");
    // One stand-in serves both upstreams, with its own admin listener as in the guard test.
    $upstreams = "{\n\tadmin 127.0.0.1:0\n\tpersist_config off\n}\nhttp://:{$gatewayPort} {\n\tbind 127.0.0.1\n"
        ."\trespond \"gateway {method} host={host} uri={uri} auth={header.Authorization}\"\n}\n"
        ."http://:{$guardPort} {\n\tbind 127.0.0.1\n\trespond \"guard uri={uri}\"\n}\n";
    $caddies = [];
    try {
        expect($adapted->exitCode())->toBe(0, $adapted->errorOutput())
            ->and(substr_count($forwarder, 'reverse_proxy http://127.0.0.1:'.$gatewayPort))->toBe(3);
        foreach (['forwarder' => $forwarder, 'upstreams' => $upstreams] as $name => $content) {
            file_put_contents("{$directory}/{$name}.Caddyfile", $content);
            $caddies[$name] = Process::start(['caddy', 'run', '--adapter', 'caddyfile', '--config', "{$directory}/{$name}.Caddyfile"]);
        }
        // Each request gives up after a second, and a Caddy that exited fails the test at once with its output: nothing waits unbounded.
        $send = static fn (string $method, string $uri): string => Http::timeout(1)->connectTimeout(1)->withToken('copy-key')
            ->send($method, "http://127.0.0.1:{$forwarderPort}{$uri}")->body();
        $deadline = microtime(true) + 3;
        while (true) {
            foreach ($caddies as $name => $caddy) {
                if (! $caddy->running()) {
                    throw new RuntimeException("The {$name} Caddy exited: ".$caddy->errorOutput());
                }
            }
            try {
                if (str_starts_with($send('GET', '/orbit/v1/copy/whoami'), 'gateway ') && str_starts_with($send('GET', '/'), 'guard ')) {
                    break;
                }
            } catch (ConnectionException) {
            }
            if (microtime(true) > $deadline) {
                throw new RuntimeException('The Caddy pair did not answer within 3 seconds.');
            }
            usleep(25_000);
        }

        expect($send('GET', '/orbit/v1/copy/whoami'))->toBe('gateway GET host=10.6.0.2 uri=/api/v1/copy/whoami auth=Bearer copy-key')
            ->and($send('POST', '/orbit/v1/copy/pull-request'))->toBe('gateway POST host=10.6.0.2 uri=/api/v1/copy/pull-request auth=Bearer copy-key')
            ->and($send('POST', '/orbit/v1/copy/handoff'))->toBe('gateway POST host=10.6.0.2 uri=/api/v1/copy/handoff auth=Bearer copy-key')
            // Nothing else of the Gateway API: a near path, an encoded escape and the API path itself all go to the guard.
            ->and($send('GET', '/orbit/v1/copy/whoami/x'))->toBe('guard uri=/orbit/v1/copy/whoami/x')
            ->and($send('GET', '/orbit/v1/copy/whoami%2F..%2F..%2Ftasks'))->toStartWith('guard ')
            ->and($send('GET', '/orbit/v1/tasks'))->toBe('guard uri=/orbit/v1/tasks')
            ->and($send('GET', '/api/v1/tasks'))->toBe('guard uri=/api/v1/tasks')
            ->and($send('POST', '/v1/responses'))->toBe('guard uri=/v1/responses');
    } finally {
        foreach ($caddies as $caddy) {
            // No graceful wait: Caddy could hold a keep-alive connection open for its grace period.
            $caddy->stop(0);
        }
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

it('keeps every other forwarder Node when one Node is added or removed', function (): void {
    $services = forwarderRouter();
    $beast = forwarderNode('beast', '10.44.0.7');
    $shark = forwarderNode('shark', '10.44.0.8');
    $admit = app(AdmitAiRouterModelForwarderAction::class);
    forwarderSucceeded($admit->execute($beast, 'orbitc0', '10.251.33.1', triggeredBy: 'test'));

    $second = forwarderSucceeded($admit->execute($shark, 'orbitc0', '10.251.34.1', triggeredBy: 'test'));
    expect($second->input['intent']['caddyfile'])->toContain("remote_ip 10.44.0.128/25 10.44.0.7 10.44.0.8\n")
        // In UFW's order: each allow inserted at the top, so the last one first.
        ->and(array_values(array_filter(forwarderRuleNames($services), static fn (string $name): bool => str_starts_with($name, 'orbit:ai-router'))))->toBe(['orbit:ai-router-task-guard-node-'.$shark->id,
            'orbit:ai-router-task-guard-node-'.$beast->id, 'orbit:ai-router-task-guard', 'orbit:ai-router-task-guard-deny']);

    $removal = $admit->execute($beast, null, null, remove: true, triggeredBy: 'test');
    expect($removal->input['intent']['caddyfile'])->toContain("remote_ip 10.44.0.128/25 10.44.0.8\n");
});

it('removes a forwarder whose only run failed, with the bridge and address of that run', function (): void {
    forwarderRouter();
    $beast = forwarderNode('beast', '10.44.0.7');
    $admit = app(AdmitAiRouterModelForwarderAction::class);
    $admit->execute($beast, 'orbitc0', '10.251.33.1', triggeredBy: 'test')->forceFill(['status' => OperationStatus::Failed, 'lock_key' => null])->save();

    $removal = $admit->execute($beast, null, null, remove: true, triggeredBy: 'test');

    expect($removal->input['intent']['forwarder'])->toMatchArray(['state' => 'absent', 'bridge' => 'orbitc0', 'address' => '10.251.33.1'])
        ->and($removal->input['intent']['caddyfile'])->toContain("remote_ip 10.44.0.128/25\n");
});

it('drops a forwarder Node that is no longer active from the guard', function (): void {
    $services = forwarderRouter();
    $beast = forwarderNode('beast', '10.44.0.7');
    forwarderSucceeded(app(AdmitAiRouterModelForwarderAction::class)->execute($beast, 'orbitc0', '10.251.33.1', triggeredBy: 'test'));
    Node::withoutEvents(fn () => $beast->forceFill(['wireguard_ip' => null])->save());

    expect(app(AdmitAiRouterTaskGuardAction::class)->execute(triggeredBy: 'test')->input['intent']['caddyfile'])->toContain("remote_ip 10.44.0.128/25\n")
        ->and(forwarderRuleNames($services))->not->toContain('orbit:ai-router-task-guard-node-'.$beast->id);
});

/**
 * Loads the rendered table on a real kernel, as root, through `tests/Support/ai-router-forwarder-nft-check.sh`. A container on a bridge,
 * behind a Docker-style FORWARD accept, reaches a stand-in forwarder but not the guard; root reaches neither, over `lo` included; the
 * forwarder's user reaches the guard. It needs Linux, passwordless sudo, nft, iptables and ip, so it is in the `privileged` group, which
 * the default suite excludes and the sudo guard lets through. The `gateway-static` CI job installs nft, runs `--group privileged` and sets
 * `ORBIT_REAL_KERNEL_TESTS=1`, which turns a missing requirement into a failure instead of a skip.
 */
it('confines the guard to containers on the bridge through the forwarder, on a real kernel', function (): void {
    $required = getenv('ORBIT_REAL_KERNEL_TESTS') === '1';
    if (PHP_OS_FAMILY !== 'Linux' || Process::run(['sudo', '-n', 'nft', '--version'])->failed()) {
        expect($required)->toBeFalse('ORBIT_REAL_KERNEL_TESTS=1 needs Linux with passwordless sudo and nft.');
        $this->markTestSkipped('Needs Linux with passwordless sudo and nft.');
    }
    $ruleset = tempnam(sys_get_temp_dir(), 'orbit-forwarder-nft-');
    file_put_contents($ruleset, app(AiRouterModelForwarder::class)->ruleset('10.44.0.3', 'ofchkb0', '169.254.78.1'));
    try {
        $check = Process::timeout(90)->run(['sudo', '-n', base_path('tests/Support/ai-router-forwarder-nft-check.sh'), $ruleset, '10.44.0.3', '8318',
            AiRouterModelForwarder::User, 'ofchkb0', '169.254.78.1', (string) AiRouterModelForwarder::Port]);
    } finally {
        unlink($ruleset);
    }

    expect($check->exitCode())->toBe(0, $check->output().$check->errorOutput())
        ->and($check->output())->toContain('ok: container to the guard, behind a FORWARD accept: dropped')
        ->toContain('ok: container to the forwarder, on the bridge: reached')
        ->toContain('ok: root to the forwarder, over lo: refused')
        ->toContain('ok: the forwarder user to the guard: reached');
})->group('privileged');
