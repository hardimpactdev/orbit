<?php

declare(strict_types=1);

use App\E2E\IncusHost;
use App\E2E\TopologyFixtureNetwork;
use App\E2E\Value\TopologyTarget;
use Symfony\Component\Process\Process;
use Tests\Support\FakeIncus;
use Tests\TestCase;

uses(TestCase::class);

/** @return array{FakeIncus, TopologyTarget, TopologyFixtureNetwork, list<array<string,mixed>>} */
function topologyNetworkFixture(): array
{
    $incus = new FakeIncus()->install();
    $target = featureTarget('TST-11');
    $nodes = [];
    foreach ($target->recipe->nodes as $node) {
        $incus->instances[$target->instance($node->key)] = [...$incus->instances[IncusHost::TEMPLATE], 'status' => 'Running', 'config' => ['user.orbit.e2e.owner' => 'orbit-e2e']];
        $nodes[] = ['key' => $node->key, 'wireguard_ip' => $node->wireGuardAddress(), 'wireguard_public_key' => base64_encode(str_repeat(chr($node->address), 32)), 'public_address' => '192.0.2.'.$node->address];
    }

    return [$incus, $target, new TopologyFixtureNetwork(new IncusHost), $nodes];
}

it('renders the hub on its Gateway and uses canonical peer actions with public input only', function (): void {
    [$incus, $target, $network, $nodes] = topologyNetworkFixture();
    $incus->respond(static function (string $instance, array $argv): array {
        $source = implode(' ', $argv);
        $field = str_contains($source, 'def persist(') ? 'persisted' : (str_contains($source, 'syncconf') ? 'applied' : 'configured');

        return [json_encode([$field => true]), '', 0];
    });

    $network->converge($target, $nodes);

    $count = count($nodes);
    expect($incus->execs)->toHaveCount(3 * $count)
        ->and($incus->execs[0]['instance'])->toBe($target->instance('gateway'))
        ->and($incus->execs[0]['argv'])->toBe(['php', '/usr/local/bin/topology-network.php'])
        ->and(json_decode($incus->execs[0]['stdin'], true))->toBe(['nodes' => $nodes]);
    foreach (array_slice($nodes, 1) as $index => $node) {
        $call = $incus->execs[$index + 1];
        expect($call['instance'])->toBe($target->instance($node['key']))
            ->and(array_slice($call['argv'], 0, 3))->toBe(['python3', '-I', '-c'])
            ->and($call['argv'][3])->toContain('the private key never leaves this host', "(WG / 'orbit.key').read_text()")
            ->and(json_decode($call['stdin'], true))->toBe([
                'public_key' => $node['wireguard_public_key'],
                'peer' => ['address' => $node['wireguard_ip'].'/24', 'server_public_key' => $nodes[0]['wireguard_public_key'],
                    'endpoint' => '192.0.2.10:51820', 'subnet' => '10.44.0.0/24'],
            ]);
    }
});

it('does not configure peers after a failed or unproved hub render', function (string $output, int $exit): void {
    [$incus, $target, $network, $nodes] = topologyNetworkFixture();
    $incus->respond(static fn (): array => [$output, 'unexported diagnostics', $exit]);

    expect(fn () => $network->converge($target, $nodes))->toThrow(RuntimeException::class, 'e2e.fixture_network_hub_failed');
    expect($incus->execs)->toHaveCount(1);
})->with([['{}', 0], ['{"configured":true}', 1]]);

it('does not activate tunnels after a failed peer configuration', function (): void {
    [$incus, $target, $network, $nodes] = topologyNetworkFixture();
    $incus->respond(static fn (string $instance, array $argv): array => $argv[0] === 'php' ? ['{"configured":true}', '', 0] : ['', 'private bytes never reported', 1]);

    expect(fn () => $network->converge($target, $nodes))->toThrow(RuntimeException::class, 'e2e.fixture_network_peer_config_failed');
    expect($incus->execs)->toHaveCount(2);
});

it('renders a real hub configuration while retaining its private key in the local filesystem', function (): void {
    $root = temporaryPath('orbit-topology-network-');
    mkdir($root, 0700, true);
    $generate = new Process(['/usr/bin/wg', 'genkey']);
    $generate->mustRun();
    $private = trim($generate->getOutput());
    $public = new Process(['/usr/bin/wg', 'pubkey'], input: $private);
    $public->mustRun();
    file_put_contents($root.'/orbit.key', $private);
    $script = $root.'/network.php';
    file_put_contents($script, str_replace(
        ['/home/orbit/orbit/apps/gateway/vendor/autoload.php', '/etc/wireguard'],
        [dirname(__DIR__, 4).'/gateway/vendor/autoload.php', $root],
        guestScriptSource('topology-network.php'),
    ));
    $peer = base64_encode(str_repeat('p', 32));
    $input = ['nodes' => [
        ['key' => 'gateway', 'wireguard_ip' => '10.44.0.1', 'wireguard_public_key' => trim($public->getOutput())],
        ['key' => 'app-dev', 'wireguard_ip' => '10.44.0.2', 'wireguard_public_key' => $peer],
    ]];
    $process = new Process([PHP_BINARY, $script], input: json_encode($input));

    $process->run();

    expect($process->getExitCode())->toBe(0, $process->getErrorOutput())
        ->and($process->getOutput())->toBe("{\"configured\":true}\n")
        ->and($process->getErrorOutput())->toBe('')
        ->and(file_get_contents($root.'/orbit.conf'))->toBe("[Interface]\nAddress = 10.44.0.1/24\nListenPort = 51820\nPrivateKey = {$private}\n\n[Peer]\n# app-dev\nPublicKey = {$peer}\nAllowedIPs = 10.44.0.2/32\n")
        ->and(fileperms($root.'/orbit.conf') & 0777)->toBe(0600);
});
