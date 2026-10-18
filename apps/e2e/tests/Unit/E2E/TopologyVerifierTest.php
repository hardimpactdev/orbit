<?php

declare(strict_types=1);

use App\E2E\IncusHost;
use App\E2E\TopologyVerifier;
use App\E2E\Value\SourceState;
use App\E2E\Value\TopologyRecipe;
use App\E2E\Value\TopologyTarget;
use Illuminate\Container\Container;
use Illuminate\Process\Factory as ProcessFactory;
use Illuminate\Support\Facades\Facade;
use Tests\Support\FakeIncus;

/** A running topology of the recipe in FakeIncus, on slot 2. */
function verifierTopology(FakeIncus $incus, TopologyRecipe $recipe): TopologyTarget
{
    $target = featureTarget('TST-9', 'a', $recipe);
    $incus->networks[$target->network()] = ['config' => ['ipv4.address' => '10.232.2.1/24', 'user.orbit.e2e.owner' => 'orbit-e2e']];
    foreach ($recipe->nodes as $node) {
        $incus->instances[$target->instance($node->key)] = [
            'type' => 'virtual-machine',
            'status' => 'Running',
            'config' => ['user.orbit.e2e.owner' => 'orbit-e2e'],
            'devices' => [
                'root' => ['type' => 'disk', 'path' => '/', 'pool' => IncusHost::POOL],
                'eth0' => ['type' => 'nic', 'network' => $target->network(), 'hwaddr' => $target->mac($node->key), 'ipv4.address' => TopologyTarget::ipv4For(2, $node->address)],
            ],
        ];
    }

    return $target;
}

/** @param array<string, int> $failures probe => remaining failing attempts */
function verifierResponder(string $sha, array &$failures = []): Closure
{
    return function (string $instance, array $argv) use (&$failures): ?array {
        if ($argv[0] !== '/usr/local/bin/verify-topology.sh') {
            return null;
        }
        [$probe, $identity, $evidenceInstance] = array_slice($argv, 1, 3);
        if (($failures[$probe] ?? 0) > 0) {
            $failures[$probe]--;

            return ['', "{$probe} is not ready\n", 1];
        }

        return [json_encode([
            'probe' => $probe,
            'passed' => true,
            'identity' => $identity,
            'checked_at' => '2026-10-12T12:00:00Z',
            'expected' => 'ready',
            'observed' => 'ready',
            'evidence_ref' => "incus://{$evidenceInstance}/{$probe}",
        ], JSON_THROW_ON_ERROR)."\n", '', 0];
    };
}

describe('TopologyVerifier', function () {
    beforeEach(function () {
        $container = new Container;
        $container->instance(ProcessFactory::class, new ProcessFactory);
        Facade::clearResolvedInstances();
        Facade::setFacadeApplication($container);
    });

    it('runs exactly the probes of the declared Nodes on the Node each belongs to', function (array $workloads, array $probes) {
        $sha = str_repeat('a', 40);
        $incus = new FakeIncus()->install();
        $target = verifierTopology($incus, TopologyRecipe::compose($workloads));
        $incus->respond(verifierResponder($sha));

        $report = new TopologyVerifier(new IncusHost, 60, 0)
            ->verify($target, new SourceState($sha, $sha, mounted: true, mountTokenHash: str_repeat('e', 64)));

        $ran = [];
        foreach ($incus->execs as $exec) {
            $ran[$exec['argv'][1]] = substr($exec['instance'], strlen('orbit-e2e-tst-9-aaaaaaaa-'));
        }
        expect($report->passed)->toBeTrue()
            ->and($ran)->toBe($probes)
            ->and($report->probes['service.vpn']['evidence_ref'])->toBe('incus://'.$target->instance('gateway').'/service.vpn');
    })->with([
        'gateway only' => [[], [
            'nodes.registered' => 'gateway', 'service.gateway' => 'gateway', 'service.vpn' => 'gateway', 'wireguard.reachability' => 'gateway',
            'operator.gateway' => 'operator', 'source.gateway' => 'gateway', 'source.operator' => 'operator',
        ]],
        'full' => [['app-dev', 'app-prod', 'app-prod-2'], [
            'nodes.registered' => 'gateway', 'service.gateway' => 'gateway', 'service.vpn' => 'gateway', 'wireguard.reachability' => 'gateway',
            'caddy.app-dev' => 'app-dev', 'caddy.app-prod' => 'app-prod', 'caddy.app-prod-2' => 'app-prod-2',
            'operator.gateway' => 'operator', 'source.gateway' => 'gateway', 'source.app-dev' => 'app-dev', 'source.operator' => 'operator',
        ]],
    ]);

    it('tells the fleet probes the declared assignments, peers, and mount token', function () {
        $sha = str_repeat('a', 40);
        $incus = new FakeIncus()->install();
        $target = verifierTopology($incus, TopologyRecipe::compose(['app-dev']));
        $incus->respond(verifierResponder($sha));

        new TopologyVerifier(new IncusHost, 60, 0)
            ->verify($target, new SourceState($sha, $sha, mounted: true, mountTokenHash: str_repeat('e', 64)));

        $arguments = [];
        foreach ($incus->execs as $exec) {
            $arguments[$exec['argv'][1]] = array_slice($exec['argv'], 4);
        }
        expect(json_decode(base64_decode($arguments['nodes.registered'][0]), true))
            ->toBe(['gateway' => ['gateway', 'vpn'], 'app-dev' => ['app-dev'], 'operator' => []])
            ->and($arguments['wireguard.reachability'])->toBe(['app-dev=10.44.0.2', 'operator=10.44.0.5'])
            ->and($arguments['source.app-dev'])->toBe([str_repeat('e', 64)])
            ->and($arguments['service.gateway'])->toBe([]);
    });

    it('retries only the pending probes and records the last failure when the window ends', function () {
        $sha = str_repeat('a', 40);
        $incus = new FakeIncus()->install();
        $target = verifierTopology($incus, TopologyRecipe::compose(['app-dev']));
        $failures = ['caddy.app-dev' => 2, 'operator.gateway' => PHP_INT_MAX];
        $incus->respond(verifierResponder($sha, $failures));

        $report = new TopologyVerifier(new IncusHost, 1, 0)
            ->verify($target, new SourceState($sha, $sha));
        $runs = array_count_values(array_map(static fn (array $exec): string => $exec['argv'][1], $incus->execs));

        expect($report->passed)->toBeFalse()
            ->and($report->probes['operator.gateway']['passed'])->toBeFalse()
            ->and($report->probes['operator.gateway']['observed'])->toContain('operator.gateway is not ready')
            ->and($report->failedSummary())->toContain('operator.gateway (expected passing evidence for source')
            ->and($runs['service.gateway'])->toBe(1)
            ->and($runs['operator.gateway'])->toBeGreaterThan(1);
    });

    it('refuses evidence for another source, probe, or Node', function (string $field, string $value) {
        $sha = str_repeat('a', 40);
        $incus = new FakeIncus()->install();
        $target = verifierTopology($incus, TopologyRecipe::compose([]));
        $honest = verifierResponder($sha);
        $incus->respond(function (string $instance, array $argv, ?string $stdin) use ($honest, $field, $value): ?array {
            $answer = $honest($instance, $argv, $stdin);
            if ($answer !== null && $argv[1] === 'service.vpn') {
                $evidence = json_decode($answer[0], true);
                $evidence[$field] = $value;
                $answer[0] = json_encode($evidence);
            }

            return $answer;
        });

        $report = new TopologyVerifier(new IncusHost, 1, 0)->verify($target, new SourceState($sha, $sha));

        expect($report->probes['service.vpn']['passed'])->toBeFalse()
            ->and($report->probes['service.vpn']['observed'])->toBe('malformed evidence');
    })->with([
        'source' => ['identity', str_repeat('b', 40)],
        'probe' => ['probe', 'service.gateway'],
        'Node' => ['evidence_ref', 'incus://elsewhere/service.vpn'],
    ]);

    it('refuses a topology whose guest left its network identity before any probe runs', function () {
        $sha = str_repeat('a', 40);
        $incus = new FakeIncus()->install();
        $target = verifierTopology($incus, TopologyRecipe::compose(['app-dev']));
        $incus->instances[$target->instance('app-dev')]['devices']['eth0']['hwaddr'] = '00:16:3e:00:00:01';

        expect(fn () => new TopologyVerifier(new IncusHost, 1, 0)->verify($target, new SourceState($sha, $sha)))
            ->toThrow(RuntimeException::class, 'MAC identity does not match topology')
            ->and($incus->execs)->toBe([]);
    });
});
