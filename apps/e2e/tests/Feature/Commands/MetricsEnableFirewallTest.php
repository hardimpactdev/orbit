<?php

declare(strict_types=1);

use App\E2E\Support\E2ECommand;
use App\E2E\Support\E2ETopologyHarness;
use App\E2E\Support\E2ETopologyKind;
use Illuminate\Contracts\Process\ProcessResult;

it('restores metrics-owned private node-exporter firewall access on Incus workload nodes', function (): void {
    $topology = e2eTopology(E2ETopologyKind::OperatorGatewayAppdevAppprodAgent, withGatewayApi: true)
        ->withCurrentCheckout(roles: ['gateway']);
    $checkout = escapeshellarg($topology->checkout('gateway'));

    try {
        metricsFirewallInstallNodeExporterStub($topology);
        e2eRestartGatewayApi($topology, 'metrics-enable-firewall');
        $state = metricsFirewallSeedIntent($topology);
        metricsFirewallRemoveBackendRules($topology, ['dev', 'prod', 'agent']);

        foreach (['agent-1', 'app-dev-1', 'app-prod-1'] as $nodeName) {
            expect($state['rules'])->toHaveKey($nodeName)
                ->and($state['rules'][$nodeName])->toMatchArray([
                    'direction' => 'incoming',
                    'action' => 'allow',
                    'source' => $state['nodes']['gateway']['wireguard_address'],
                    'destination' => null,
                    'port' => '9100',
                    'protocol' => 'tcp',
                    'address_family' => 'v4',
                    'interface' => 'wireguard',
                    'owner' => 'metrics',
                    'protected' => true,
                ]);

            $restore = $topology->ssh(
                'gateway',
                "cd {$checkout} && orbit doctor --node=".escapeshellarg($nodeName).' --family=firewall_rule --key=firewall_rule.rule_missing --restore --json',
                timeoutSeconds: 180,
            );
            $doctor = e2eJsonCommandData(e2eJsonCommandPayload($restore->output()));

            expect($restore->successful())->toBeTrue($restore->output().$restore->errorOutput())
                ->and($doctor['doctor']['healthy'])->toBeTrue(json_encode($doctor, JSON_PRETTY_PRINT));

            $scrape = metricsFirewallScrapeNodeExporter($topology, $state['nodes'][$nodeName]['wireguard_address']);

            expect($scrape->successful())->toBeTrue($scrape->output().$scrape->errorOutput());
        }
    } finally {
        $topology->cleanup();
    }
})->group('e2e-feature', 'e2e-provider-incus', 'e2e-feature-operator_gateway_app-dev_app-prod_agent');

function metricsFirewallInstallNodeExporterStub(E2ETopologyHarness $topology): void
{
    $script = <<<'SH'
sudo tee /usr/local/bin/node_exporter >/dev/null <<'PHP'
#!/usr/bin/env php
<?php

if (in_array('--version', $argv, true)) {
    fwrite(STDOUT, "node_exporter, version 1.11.1\n");

    exit(0);
}

$listen = '0.0.0.0:9100';

foreach ($argv as $argument) {
    if (str_starts_with($argument, '--web.listen-address=')) {
        $listen = substr($argument, strlen('--web.listen-address='));
    }
}

[$host, $port] = array_pad(explode(':', $listen, 2), 2, '9100');
$server = stream_socket_server("tcp://{$host}:{$port}", $errno, $errstr);

if ($server === false) {
    fwrite(STDERR, "node_exporter stub listen failed: {$errstr}\n");

    exit(1);
}

while ($connection = @stream_socket_accept($server, -1)) {
    $request = fgets($connection) ?: '';
    $path = explode(' ', trim($request))[1] ?? '/';

    while (($line = fgets($connection)) !== false && trim($line) !== '') {
        // Drain headers.
    }

    if ($path !== '/metrics') {
        fwrite($connection, "HTTP/1.1 404 Not Found\r\nContent-Length: 0\r\n\r\n");
        fclose($connection);

        continue;
    }

    $body = "# HELP orbit_e2e_node_exporter_up E2E node exporter stub\n# TYPE orbit_e2e_node_exporter_up gauge\norbit_e2e_node_exporter_up 1\n";

    fwrite($connection, "HTTP/1.1 200 OK\r\nContent-Type: text/plain; version=0.0.4\r\nContent-Length: ".strlen($body)."\r\n\r\n{$body}");
    fclose($connection);
}
PHP
sudo chmod 0755 /usr/local/bin/node_exporter
/usr/local/bin/node_exporter --version >/dev/null
sudo tee /etc/systemd/system/node-exporter.service >/dev/null <<'UNIT'
[Unit]
Description=Orbit E2E node exporter stub

[Service]
ExecStart=/usr/local/bin/node_exporter --web.listen-address=0.0.0.0:9100
Restart=always

[Install]
WantedBy=multi-user.target
UNIT
sudo systemctl daemon-reload
sudo systemctl restart node-exporter.service
SH;

    foreach (['dev', 'prod', 'agent'] as $role) {
        $result = $topology->ssh($role, $script, timeoutSeconds: 120);

        expect($result->successful())->toBeTrue($result->output().$result->errorOutput());
    }
}

/**
 * @return array{
 *     nodes: array<string, array{wireguard_address: string}>,
 *     rules: array<string, array<string, mixed>>,
 * }
 */
function metricsFirewallSeedIntent(E2ETopologyHarness $topology): array
{
    $php = <<<'PHP'
$nodes = \App\Models\Node::query()
    ->whereIn('name', ['gateway', 'agent-1', 'app-dev-1', 'app-prod-1'])
    ->get()
    ->mapWithKeys(fn (\App\Models\Node $node): array => [
        $node->name => [
            'wireguard_address' => (string) $node->wireguard_address,
        ],
    ])
    ->all();

$gateway = \App\Models\Node::query()->where('name', 'gateway')->firstOrFail();
$targetNodes = \App\Models\Node::query()
    ->whereIn('name', ['agent-1', 'app-dev-1', 'app-prod-1'])
    ->get();
$rules = [];

foreach ($targetNodes as $node) {
    $reason = 'Allow metrics node gateway to scrape node-exporter.';
    $shape = [
        'direction' => 'incoming',
        'action' => 'allow',
        'source' => (string) $gateway->wireguard_address,
        'destination' => null,
        'port' => '9100',
        'protocol' => 'tcp',
    ];
    $rule = \App\Models\FirewallRule::query()->updateOrCreate(
        ['node_id' => $node->id, 'name' => 'orbit-metrics-node-exporter'],
        [
            ...$shape,
            'reason' => $reason,
            'source_hash' => hash('sha256', json_encode([
                'node' => $node->name,
                'name' => 'orbit-metrics-node-exporter',
                'shape' => $shape,
                'reason' => $reason,
            ], JSON_THROW_ON_ERROR)),
            'address_family' => 'v4',
            'interface' => 'wireguard',
            'owner' => 'metrics',
            'protected' => true,
        ],
    );

    $rules[$node->name] = [
        'direction' => $rule->direction,
        'action' => $rule->action,
        'source' => $rule->source,
        'destination' => $rule->destination,
        'port' => (string) $rule->port,
        'protocol' => $rule->protocol,
        'address_family' => $rule->address_family,
        'interface' => $rule->interface,
        'owner' => $rule->owner,
        'protected' => $rule->protected,
    ];
}

echo json_encode([
    'nodes' => $nodes,
    'rules' => $rules,
], JSON_THROW_ON_ERROR);
PHP;

    $result = E2ECommand::gatewayArtisan(
        $topology->instance('gateway'),
        'tinker --execute='.escapeshellarg($php),
        'Could not read metrics firewall state',
        timeoutSeconds: 120,
    );

    expect($result->successful())->toBeTrue($result->output().$result->errorOutput());

    /** @var array{nodes: array<string, array{wireguard_address: string}>, rules: array<string, array<string, mixed>>} $state */
    $state = json_decode(trim($result->output()), associative: true, flags: JSON_THROW_ON_ERROR);

    return $state;
}

/**
 * @param  list<string>  $roles
 */
function metricsFirewallRemoveBackendRules(E2ETopologyHarness $topology, array $roles): void
{
    $script = <<<'SH'
if command -v ufw >/dev/null 2>&1; then
    iface="$(ip -o link show type wireguard 2>/dev/null | awk -F': ' '{print $2; exit}')"

    if [ -n "$iface" ]; then
        sudo ufw delete allow in on "$iface" from 10.6.0.2 to any port 9100 proto tcp >/dev/null 2>&1 || true
    fi
fi
SH;

    foreach ($roles as $role) {
        $result = $topology->ssh($role, $script, timeoutSeconds: 60);

        expect($result->successful())->toBeTrue($result->output().$result->errorOutput());
    }
}

function metricsFirewallScrapeNodeExporter(E2ETopologyHarness $topology, string $wireguardAddress): ProcessResult
{
    $url = escapeshellarg("http://{$wireguardAddress}:9100/metrics");
    $script = sprintf(
        <<<'SH'
for attempt in $(seq 1 20); do
    if curl -fsS --max-time 5 %s | grep -q '^# HELP '; then
        exit 0
    fi

    sleep 2
done

curl -v --max-time 5 %s
SH,
        $url,
        $url,
    );

    return $topology->ssh('gateway', $script, timeoutSeconds: 140, allowFailure: true);
}
