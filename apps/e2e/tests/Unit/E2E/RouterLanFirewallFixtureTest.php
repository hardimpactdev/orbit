<?php

declare(strict_types=1);

use App\E2E\RouterLanFirewallFixture;
use App\E2E\Value\GuestCommand;
use App\E2E\Value\GuestCommandResult;

/** @return array<string, mixed> */
function lanFixturePlan(): array
{
    return ['issue' => RouterLanFirewallFixture::ISSUE, 'attempt' => RouterLanFirewallFixture::ATTEMPT,
        'router' => '10.232.1.11', 'member' => '10.232.1.12', 'nonmember' => '10.232.1.14', 'vpn' => '10.44.0.1'];
}

/** @return array{fixture: RouterLanFirewallFixture, calls: ArrayObject} */
function lanFixtureResponder(string $phase = 'narrowed', ?Closure $override = null): array
{
    $calls = new ArrayObject;
    $fixture = new RouterLanFirewallFixture(function (string $node, GuestCommand $command) use ($phase, $override, $calls): GuestCommandResult {
        $calls[] = [$node, $command];
        if ($override !== null && ($answer = $override($node, $command)) !== null) {
            return $answer;
        }
        $argv = $command->command;
        if (in_array('python3', $argv, true)) {
            return new GuestCommandResult(json_encode(['phase' => $phase, 'certificate' => 'public-cert', 'public_key_pin' => 'sha256//'.base64_encode(str_repeat('a', 32)), 'inventory_matches' => true,
                'health' => ['lan_udp_tcp' => true, 'nested_bridge_udp_tcp' => true, 'loopback_udp_tcp' => true, 'https' => true]], JSON_THROW_ON_ERROR), '', 0);
        }
        if (in_array('node:list', $argv, true)) {
            return new GuestCommandResult(json_encode(['nodes' => [
                ['id' => 2, 'name' => 'app-dev', 'status' => 'active', 'roles' => ['app-dev', 'router'], 'cluster_id' => 1, 'lan_ip' => '10.232.1.11'],
                ['id' => 3, 'name' => 'app-prod', 'status' => 'active', 'roles' => ['app-prod'], 'cluster_id' => 1, 'lan_ip' => '10.232.1.12'],
                ['id' => 4, 'name' => 'operator', 'status' => 'active', 'roles' => [], 'cluster_id' => null],
            ]], JSON_THROW_ON_ERROR), '', 0);
        }
        if (str_ends_with(end($argv), '/managed-firewall-rules')) {
            $data = [];
            foreach (['https' => ['tcp', '443'], 'dns-udp' => ['udp', '53'], 'dns-tcp' => ['tcp', '53']] as $name => [$proto, $port]) {
                $data[] = ['name' => 'orbit:router-lan-'.$name.':3', 'role' => 'router', 'action' => 'allow',
                    'source' => '10.232.1.12', 'destination' => '10.232.1.11', 'port' => $port, 'protocol' => $proto, 'interface' => null];
            }

            return new GuestCommandResult(json_encode(['data' => $data], JSON_THROW_ON_ERROR), '', 0);
        }
        if (in_array('dig', $argv, true)) {
            if (in_array('gateway.orbit', $argv, true)) {
                return new GuestCommandResult("10.44.0.1\n", '', 0);
            }
            if ($node === 'operator' && ! in_array($phase, ['overlap', 'rolled-back'], true)) {
                return new GuestCommandResult('communications error: timed out; no servers could be reached', '', 9);
            }

            return new GuestCommandResult("192.0.2.179\n", '', 0);
        }
        if (in_array('--write-out', $argv, true)) {
            return new GuestCommandResult('200', '', 0);
        }

        return new GuestCommandResult('{"data":[]}', '', 0);
    });

    return compact('fixture', 'calls');
}

it('refuses another issue attempt or arbitrary operation before any command', function (string $issue, string $attempt, string $operation): void {
    ['fixture' => $fixture, 'calls' => $calls] = lanFixtureResponder();
    $plan = array_replace(lanFixturePlan(), compact('issue', 'attempt'));

    expect(fn () => $fixture->run($operation, $plan))->toThrow(RuntimeException::class, 'authorized only')
        ->and($calls)->toHaveCount(0);
})->with([
    ['TASK-1792', RouterLanFirewallFixture::ATTEMPT, 'prepare'],
    [RouterLanFirewallFixture::ISSUE, str_repeat('a', 32), 'restore'],
    [RouterLanFirewallFixture::ISSUE, RouterLanFirewallFixture::ATTEMPT, 'shell'],
]);

it('binds real member and nonmember DNS probes to LAN sources with positive controls around denial', function (): void {
    ['fixture' => $fixture, 'calls' => $calls] = lanFixtureResponder();
    $result = $fixture->run('probe', lanFixturePlan());
    $dns = array_values(array_filter($calls->getArrayCopy(), static fn (array $call): bool => in_array('router-firewall.invalid', $call[1]->command, true)));

    expect($result['acceptance'])->toBe('member-only UDP/TCP, HTTPS, bridge and VPN verified')
        ->and(array_column($dns, 0))->toBe(['app-prod', 'app-prod', 'operator', 'operator', 'app-prod', 'app-prod']);
    foreach ($dns as [$node, $command]) {
        expect($command->command)->toContain('-b', $node === 'operator' ? '10.232.1.14' : '10.232.1.12', '@10.232.1.11', 'ORBIT_CHECK_PROCESSES=1');
    }
    $root = array_values(array_filter($calls->getArrayCopy(), static fn (array $call): bool => in_array('python3', $call[1]->command, true)));
    expect($root)->toHaveCount(2)
        ->and($root[0][1]->stdin)->toContain('class Fixture:');
    $https = array_values(array_filter($calls->getArrayCopy(), static fn (array $call): bool => in_array('--pinnedpubkey', $call[1]->command, true)));
    expect($https)->toHaveCount(1)
        ->and($https[0][1]->command)->toContain('sha256//'.base64_encode(str_repeat('a', 32)), '--interface', '10.232.1.12');
});

it('requires nonmember success while the previous overlap is restored', function (): void {
    ['fixture' => $fixture] = lanFixtureResponder('rolled-back');
    expect($fixture->run('probe', lanFixturePlan())['acceptance'])->toBe('previous overlap exposure and continuity verified');
});

it('rejects missing listeners refused connections false denials and member failures', function (string $node, string $output, int $exit): void {
    ['fixture' => $fixture] = lanFixtureResponder(override: static function (string $current, GuestCommand $command) use ($node, $output, $exit): ?GuestCommandResult {
        return $current === $node && in_array('router-firewall.invalid', $command->command, true)
            ? new GuestCommandResult($output, '', $exit) : null;
    });
    expect(fn () => $fixture->run('probe', lanFixturePlan()))->toThrow(RuntimeException::class, 'not denial evidence');
})->with([
    ['operator', 'connection refused', 9], ['operator', "192.0.2.179\n", 0],
    ['operator', 'could not bind source', 9], ['app-prod', 'timed out', 9],
]);

it('rejects a different HTTPS public key without installing trust files', function (): void {
    ['fixture' => $fixture] = lanFixtureResponder(override: static fn (string $node, GuestCommand $command): ?GuestCommandResult => in_array('--pinnedpubkey', $command->command, true)
        ? new GuestCommandResult('', 'SSL public key does not match pinned public key', 90) : null);

    expect(fn () => $fixture->run('probe', lanFixturePlan()))->toThrow(RuntimeException::class, 'does not match pinned');
});

it('does not probe after an unhealthy root control and keeps the failure diagnostic', function (): void {
    ['fixture' => $fixture, 'calls' => $calls] = lanFixtureResponder(override: static fn (string $node, GuestCommand $command): ?GuestCommandResult => in_array('python3', $command->command, true)
        ? new GuestCommandResult('', 'listener failed; journal retained', 1) : null);

    expect(fn () => $fixture->run('probe', lanFixturePlan()))->toThrow(RuntimeException::class, 'journal retained');
    expect(array_filter($calls->getArrayCopy(), static fn (array $call): bool => in_array('dig', $call[1]->command, true)))->toBe([]);
});
