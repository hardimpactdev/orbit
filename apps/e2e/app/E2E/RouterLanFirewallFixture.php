<?php

declare(strict_types=1);

namespace App\E2E;

use App\E2E\Value\GuestCommand;
use App\E2E\Value\GuestCommandResult;
use Closure;
use RuntimeException;

/** The explicitly authorized, fixed-scope TASK-1791 acceptance control. */
final readonly class RouterLanFirewallFixture
{
    public const string ISSUE = 'TASK-1791';

    public const string ATTEMPT = '8b819011a28fd78fbb64149fa1b814ae';

    public const array OPERATIONS = ['prepare', 'overlap', 'narrow', 'rollback', 'probe', 'restore', 'inspect'];

    /** @param Closure(string, GuestCommand): GuestCommandResult $execute */
    public function __construct(private Closure $execute) {}

    /** @param array<string, mixed> $plan
     * @return array<string, mixed> */
    public function run(string $operation, array $plan): array
    {
        if (! in_array($operation, self::OPERATIONS, true)
            || ($plan['issue'] ?? null) !== self::ISSUE || ($plan['attempt'] ?? null) !== self::ATTEMPT) {
            throw new RuntimeException('The firewall fixture is authorized only for the retained TASK-1791 attempt and fixed operations.');
        }
        $routerAddress = $this->text($plan, 'router');
        $memberAddress = $this->text($plan, 'member');
        $nonmemberAddress = $this->text($plan, 'nonmember');
        $vpnAddress = $this->text($plan, 'vpn');
        $nodes = $this->records($this->json($this->call('operator', ['orbit', 'node:list', '--json'])), 'nodes');
        $byName = [];
        foreach ($nodes as $node) {
            $byName[$this->text($node, 'name')] = $node;
        }
        $router = $byName['app-dev'] ?? [];
        $member = $byName['app-prod'] ?? [];
        $outsider = $byName['operator'] ?? [];
        $roles = $router['roles'] ?? [];
        $routerId = $router['id'] ?? null;
        $memberId = $member['id'] ?? null;
        if (! is_int($routerId) || ! is_int($memberId) || ! is_array($roles)
            || ($outsider['status'] ?? null) !== 'active'
            || ($router['status'] ?? null) !== 'active' || ! in_array('router', $roles, true)
            || ($router['cluster_id'] ?? null) === null
            || ($member['status'] ?? null) !== 'active' || ($member['cluster_id'] ?? null) !== $router['cluster_id']
            || ($outsider['cluster_id'] ?? null) === $router['cluster_id']
            || ($router['lan_ip'] ?? null) !== $plan['router'] || ($member['lan_ip'] ?? null) !== $plan['member']) {
            throw new RuntimeException('Prepare app-dev Router, app-prod member and operator nonmember through the Orbit CLI first.');
        }
        $managed = $this->records($this->api('/nodes/'.$routerId.'/managed-firewall-rules'), 'data');
        foreach (['https' => ['tcp', '443'], 'dns-udp' => ['udp', '53'], 'dns-tcp' => ['tcp', '53']] as $family => [$protocol, $port]) {
            $expected = [
                'name' => 'orbit:router-lan-'.$family.':'.$memberId, 'role' => 'router', 'action' => 'allow',
                'source' => $plan['member'], 'destination' => $plan['router'], 'port' => $port,
                'protocol' => $protocol, 'interface' => null,
            ];
            if (! array_any($managed, static fn (array $rule): bool => count($rule) === count($expected)
                && array_all($expected, static fn (mixed $value, string $key): bool => array_key_exists($key, $rule) && $rule[$key] === $value))) {
                throw new RuntimeException('The expected exact member managed rules are not published.');
            }
        }
        $plan['operator_rules'] = $this->records($this->api('/firewall-rules'), 'data');
        $control = $operation === 'probe' ? 'inspect' : $operation;
        $script = file_get_contents(__DIR__.'/../../resources/guest/router-lan-firewall.py');
        if ($script === false) {
            throw new RuntimeException('The maintained firewall fixture control is missing.');
        }
        $command = new GuestCommand([
            'env', 'ORBIT_ROUTER_LAN_FIREWALL_FIXTURE=1', 'python3', '-', $control,
            json_encode($plan, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES),
        ], 180, $script);
        $report = $this->json(($this->execute)('app-dev', $command));
        if ($operation === 'probe') {
            $health = $report['health'] ?? null;
            if (($report['inventory_matches'] ?? null) !== true || ! is_array($health)
                || ! array_all(['lan_udp_tcp', 'nested_bridge_udp_tcp', 'loopback_udp_tcp', 'https'], static fn (string $key): bool => ($health[$key] ?? null) === true)) {
                throw new RuntimeException('Positive controls or inventory failed; no denial evidence is valid.');
            }
            if (! in_array($report['phase'] ?? null, ['prepared', 'overlap', 'narrowed', 'rolled-back'], true)) {
                throw new RuntimeException('Probe needs an active fixture.');
            }
            $broad = in_array($report['phase'], ['overlap', 'rolled-back'], true);
            $pin = $this->text($report, 'public_key_pin');
            if (preg_match('/\Asha256\/\/[A-Za-z0-9+\/]{43}=\z/', $pin) !== 1) {
                throw new RuntimeException('Invalid fixture public-key pin.');
            }
            $this->dns('app-prod', $memberAddress, $routerAddress, true);
            $this->dns('operator', $nonmemberAddress, $routerAddress, $broad);
            $this->dns('app-prod', $memberAddress, $routerAddress, true);
            $https = $this->call('app-prod', [
                'curl', '--fail', '--silent', '--show-error', '--max-time', '5', '--interface', $memberAddress,
                '--insecure', '--pinnedpubkey', $pin, '--output', '/dev/null', '--write-out', '%{http_code}', 'https://'.$routerAddress.'/',
            ]);
            if (trim($https->stdout) !== '200') {
                throw new RuntimeException('Router LAN HTTPS did not return 200.');
            }
            foreach (['+notcp', '+tcp'] as $transport) {
                $vpn = $this->call('app-prod', ['dig', $transport, '+short', '+time=2', '+tries=1', '@'.$vpnAddress, 'gateway.orbit', 'A']);
                if (trim($vpn->stdout) !== $vpnAddress) {
                    throw new RuntimeException('Existing VPN DNS no longer answers.');
                }
            }
            $afterProbe = $this->json(($this->execute)('app-dev', $command));
            if (($afterProbe['health'] ?? null) !== $health || ($afterProbe['inventory_matches'] ?? null) !== true) {
                throw new RuntimeException('Positive controls/inventory changed after probes; no denial evidence is valid.');
            }
            $report = $afterProbe;
            $report['acceptance'] = $broad ? 'previous overlap exposure and continuity verified' : 'member-only UDP/TCP, HTTPS, bridge and VPN verified';
        }
        $after = $this->records($this->api('/firewall-rules'), 'data');
        if ($after !== $plan['operator_rules']) {
            throw new RuntimeException('Operator records changed during acceptance; retain diagnostics and inspect ownership.');
        }

        return $report;
    }

    private function dns(string $node, string $source, string $destination, bool $allowed): void
    {
        foreach (['+notcp', '+tcp'] as $transport) {
            $result = ($this->execute)($node, GuestCommand::asOrbitUser([
                'env', 'ORBIT_ROUTER_LAN_FIREWALL_FIXTURE=1', 'dig', '-b', $source, '@'.$destination, $transport,
                '+short', '+time=2', '+tries=1', 'router-firewall.invalid', 'A',
            ], 15));
            if ($allowed ? (! $result->successful() || trim($result->stdout) !== '192.0.2.179')
                : ($result->exitCode !== 9 || ! str_contains(strtolower($result->stdout.$result->stderr), 'timed out'))) {
                throw new RuntimeException("Unexpected {$transport} DNS result from {$node}; a missing/refused listener is not denial evidence.");
            }
        }
    }

    /** @return array<string, mixed> */
    private function api(string $path): array
    {
        return $this->json($this->call('operator', [
            'curl', '--fail', '--silent', '--show-error', '--max-time', '20',
            '--cacert', '/home/orbit/.orbit/e2e-gateway-root-ca.pem', 'https://10.44.0.1/api/v1'.$path,
        ]));
    }

    /** @param list<string> $argv */
    private function call(string $node, array $argv): GuestCommandResult
    {
        $result = ($this->execute)($node, GuestCommand::asOrbitUser(['env', 'ORBIT_ROUTER_LAN_FIREWALL_FIXTURE=1', ...$argv], 60));
        if (! $result->successful()) {
            throw new RuntimeException('Fixture command failed on '.$node.': '.$result->stderr);
        }

        return $result;
    }

    /** @return array<string, mixed> */
    private function json(GuestCommandResult $result): array
    {
        if (! $result->successful()) {
            throw new RuntimeException('Fixture control failed; baseline and diagnostics retained: '.$result->stderr.$result->stdout);
        }
        $value = json_decode($result->stdout, true, 512, JSON_THROW_ON_ERROR);
        if (! is_array($value)) {
            throw new RuntimeException('Invalid fixture response.');
        }

        $result = [];
        foreach ($value as $key => $item) {
            if (! is_string($key)) {
                throw new RuntimeException('Expected a JSON object.');
            }
            $result[$key] = $item;
        }

        return $result;
    }

    /** @param array<string, mixed> $value */
    private function text(array $value, string $key): string
    {
        $text = $value[$key] ?? null;
        if (! is_string($text) || $text === '') {
            throw new RuntimeException('Missing fixture string: '.$key);
        }

        return $text;
    }

    /** @param array<string, mixed> $value
     * @return list<array<string, mixed>> */
    private function records(array $value, string $key): array
    {
        $records = $value[$key] ?? null;
        if (! is_array($records) || ! array_is_list($records)) {
            throw new RuntimeException('Missing fixture record list: '.$key);
        }
        $result = [];
        foreach ($records as $record) {
            if (! is_array($record)) {
                throw new RuntimeException('Invalid fixture record.');
            }
            $row = [];
            foreach ($record as $name => $item) {
                if (! is_string($name)) {
                    throw new RuntimeException('Invalid fixture record key.');
                }
                $row[$name] = $item;
            }
            $result[] = $row;
        }

        return $result;
    }
}
