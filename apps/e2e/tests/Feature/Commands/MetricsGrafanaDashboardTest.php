<?php

declare(strict_types=1);

use App\E2E\Support\E2ETopologyHarness;
use App\E2E\Support\E2ETopologyKind;

it('provisions the Grafana node resources dashboard when metrics is enabled on an Incus gateway', function (): void {
    $topology = e2eTopology(E2ETopologyKind::OperatorGateway, withGatewayApi: true)
        ->withCurrentCheckout(roles: ['gateway']);

    try {
        e2eRestartGatewayApi($topology, 'metrics-grafana-dashboard');
        metricsGrafanaDashboardInstallNodeExporterStub($topology);

        $enable = $topology->ssh(
            'gateway',
            'cd '.escapeshellarg($topology->checkout('gateway')).' && orbit metrics:enable --node=gateway --json',
            timeoutSeconds: 420,
        );
        $payload = e2eJsonCommandPayload($enable->output());
        $data = e2eJsonCommandData($payload);

        expect($enable->successful())->toBeTrue($enable->output().$enable->errorOutput())
            ->and($data['assignment']['role'])->toBe('metrics')
            ->and($data['assignment']['status'])->toBe('active');

        $artifacts = $topology->ssh(
            'gateway',
            <<<'SH'
sudo test -s /var/lib/orbit/processes/grafana/provisioning/datasources/prometheus.yml
sudo grep -F 'uid: orbit-prometheus' /var/lib/orbit/processes/grafana/provisioning/datasources/prometheus.yml
sudo test -s /var/lib/orbit/processes/grafana/provisioning/dashboards/orbit-node-resources.yml
sudo grep -F 'path: /var/lib/grafana/dashboards' /var/lib/orbit/processes/grafana/provisioning/dashboards/orbit-node-resources.yml
sudo test -s /var/lib/orbit/processes/grafana/dashboards/orbit-node-resources.json
sudo grep -F '"title": "Orbit Node Resources"' /var/lib/orbit/processes/grafana/dashboards/orbit-node-resources.json
sudo grep -F 'label_values(up{job=\"orbit-node-exporter\"}, node)' /var/lib/orbit/processes/grafana/dashboards/orbit-node-resources.json
sudo grep -F '"value": "gateway"' /var/lib/orbit/processes/grafana/dashboards/orbit-node-resources.json
sudo grep -F 'node_memory_MemAvailable_bytes' /var/lib/orbit/processes/grafana/dashboards/orbit-node-resources.json
SH,
            timeoutSeconds: 60,
        );

        expect($artifacts->successful())->toBeTrue($artifacts->output().$artifacts->errorOutput());
    } finally {
        metricsGrafanaDashboardCleanup($topology);
        $topology->cleanup();
    }
})->group('e2e-feature', 'e2e-provider-incus', 'e2e-feature-operator_gateway', 'e2e-feature-operator-gateway');

function metricsGrafanaDashboardInstallNodeExporterStub(E2ETopologyHarness $topology): void
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
SH;

    $result = $topology->ssh('gateway', $script, timeoutSeconds: 120);

    expect($result->successful())->toBeTrue($result->output().$result->errorOutput());
}

function metricsGrafanaDashboardCleanup(E2ETopologyHarness $topology): void
{
    $topology->ssh(
        'gateway',
        <<<'SH'
docker service rm orbit-grafana orbit-prometheus >/dev/null 2>&1 || true
sudo systemctl stop node-exporter.service >/dev/null 2>&1 || true
sudo rm -f /etc/systemd/system/node-exporter.service
sudo systemctl daemon-reload >/dev/null 2>&1 || true
sudo rm -rf /var/lib/orbit/processes/grafana /var/lib/orbit/processes/prometheus
sudo rm -f /usr/local/bin/node_exporter
SH,
        timeoutSeconds: 120,
        allowFailure: true,
    );
}
