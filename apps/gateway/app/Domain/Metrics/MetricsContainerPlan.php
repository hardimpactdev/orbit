<?php

declare(strict_types=1);

namespace App\Domain\Metrics;

/**
 * The canonical Prometheus and Grafana container specifications, their systemd units and the files that own them.
 * The native Metrics workflows apply this plan with official behaviors; it holds public data only.
 */
final readonly class MetricsContainerPlan
{
    public const string HashLabel = 'com.orbit.metrics.spec-hash';

    private const array Paths = [
        '/etc/orbit/metrics/prometheus.yml',
        '/etc/orbit/metrics/grafana/grafana.ini',
        '/etc/orbit/metrics/grafana/provisioning/datasources/prometheus.yml',
        '/etc/orbit/metrics/grafana/provisioning/dashboards/provider.yml',
        '/etc/orbit/metrics/grafana/dashboards/orbit-node-resources.json',
        '/etc/orbit/metrics/grafana/dashboards/orbit-caddy.json',
        '/etc/orbit/metrics/grafana/dashboards/orbit-fpm.json',
        '/etc/orbit/metrics/grafana/admin-password',
    ];

    /**
     * @param  array<string,string>  $configuration  Metrics configuration files by path; never the admin password
     * @return array{services:list<array{name:string,image:string,unit:string,volume_args:list<string>,argv:list<string>,check:array{volumes:list<string>,restart:string,ports:list<string>}}>,files:list<array{path:string,content:string}>}
     */
    public function for(int $assignment, string $address, array $configuration): array
    {
        if (array_filter(array_keys($configuration), static fn (string $path): bool => str_ends_with($path, '/admin-password')) !== []) {
            throw new \InvalidArgumentException('metrics.public_plan_private_field');
        }
        $services = array_map(fn (string $service): array => $this->unit($service, $assignment, $address, $configuration), ['prometheus', 'grafana']);
        $files = [['path' => '/etc/orbit/metrics/.orbit-owner', 'content' => "metrics\n"]];
        foreach ($services as $service) {
            $files[] = ['path' => '/etc/systemd/system/'.$service['name'].'.service', 'content' => $service['unit']];
        }

        return ['services' => $services, 'files' => $files];
    }

    /**
     * @param  array<string,string>  $configuration
     * @return array{name:string,image:string,unit:string,volume_args:list<string>,argv:list<string>,check:array{volumes:list<string>,restart:string,ports:list<string>}}
     */
    private function unit(string $service, int $assignment, string $address, array $configuration): array
    {
        $spec = $this->spec($service, $assignment, $address, $configuration);
        $quoted = implode(' ', array_map(
            fn (string $value): string => str_replace('$', '$$', $this->json(str_replace('%', '%%', $value))),
            $spec['args'],
        ));

        return [
            'name' => $this->name($service),
            'image' => $this->image($service),
            'unit' => "[Unit]\nDescription=Orbit Metrics {$service}\nAfter=docker.service network-online.target\nRequires=docker.service\n"
                ."[Service]\nType=oneshot\nRemainAfterExit=yes\nExecStart=/usr/bin/docker {$quoted}\n",
            'volume_args' => ['/usr/bin/docker', 'volume', 'create', ...$this->labelArgs($this->labels($service, true)), $this->volume($service)],
            'argv' => ['timeout', '60', '/usr/bin/docker', ...$spec['args']],
            'check' => ['volumes' => $spec['volumes'], 'restart' => 'unless-stopped', 'ports' => []],
        ];
    }

    /**
     * @param  array<string,string>  $configuration
     * @return array{args:list<string>,volumes:list<string>}
     */
    private function spec(string $service, int $assignment, string $address, array $configuration): array
    {
        $prometheus = $service === 'prometheus';
        $args = ['container', 'run', '--detach', '--pull=never', '--name', $this->name($service), '--restart', 'unless-stopped',
            '--network', 'host', '--log-driver', 'json-file', '--log-opt', 'max-size=10m', '--log-opt', 'max-file=3',
            ...$this->labelArgs($this->labels($service, false)),
            '--label', "com.orbit.metrics.assignment={$assignment}",
            '--mount', 'type=volume,source='.$this->volume($service).',target='.($prometheus ? '/prometheus' : '/var/lib/grafana')];
        $binds = $prometheus
            ? [self::Paths[0].':/etc/prometheus/prometheus.yml:ro']
            : [self::Paths[1].':/etc/grafana/grafana.ini:ro', '/etc/orbit/metrics/grafana/provisioning:/etc/grafana/provisioning:ro',
                '/etc/orbit/metrics/grafana/dashboards:/var/lib/grafana/dashboards:ro', self::Paths[7].':/run/orbit/grafana-admin-password:ro'];
        foreach ($binds as $bind) {
            array_push($args, '--volume', $bind);
        }
        $environment = $prometheus ? [] : ['GF_SECURITY_ADMIN_USER=admin', 'GF_SECURITY_ADMIN_PASSWORD__FILE=/run/orbit/grafana-admin-password',
            "GF_SERVER_HTTP_ADDR={$address}", 'GF_SERVER_HTTP_PORT=3000'];
        foreach ($environment as $variable) {
            array_push($args, '--env', $variable);
        }
        $health = $prometheus ? 'http://127.0.0.1:9090/-/ready' : "http://{$address}:3000/api/health";
        array_push($args, '--health-cmd', "wget --no-verbose --tries=1 --spider {$health}", '--health-start-period', '30s',
            '--health-interval', '5s', '--health-retries', '12');
        $command = $prometheus ? ['--config.file=/etc/prometheus/prometheus.yml', '--storage.tsdb.path=/prometheus',
            '--storage.tsdb.retention.time=7d', '--web.listen-address=127.0.0.1:9090'] : [];
        $inputs = array_map(static fn (string $path): array => [$path, $configuration[$path] ?? null],
            $prometheus ? array_slice(self::Paths, 0, 1) : array_slice(self::Paths, 1, 3));
        // The hash names the spec on the container; it must stay stable across releases, so the input is encoded
        // exactly as the earlier Swamp-side renderer encoded it.
        $hash = hash('sha256', $this->json([$args, $this->image($service), $command, $inputs]));

        return [
            'args' => [...$args, '--label', self::HashLabel."={$hash}", '--', $this->image($service), ...$command],
            'volumes' => [$this->volume($service).($prometheus ? ':/prometheus' : ':/var/lib/grafana'), ...$binds],
        ];
    }

    private function name(string $service): string
    {
        return "orbit-metrics-{$service}";
    }

    private function volume(string $service): string
    {
        return $this->name($service).'-data';
    }

    private function image(string $service): string
    {
        return $service === 'prometheus' ? 'prom/prometheus:v3.5.0' : 'grafana/grafana:12.1.1';
    }

    /** @return array<string,string> */
    private function labels(string $service, bool $volume): array
    {
        $labels = ['com.orbit.managed' => 'metrics', 'com.orbit.metrics.'.($volume ? 'volume' : 'service') => $service];
        ksort($labels, SORT_STRING);

        return $labels;
    }

    /**
     * @param  array<string,string>  $labels
     * @return list<string>
     */
    private function labelArgs(array $labels): array
    {
        $args = [];
        foreach ($labels as $key => $value) {
            array_push($args, '--label', "{$key}={$value}");
        }

        return $args;
    }

    /** JSON as JavaScript's JSON.stringify writes it. */
    private function json(mixed $value): string
    {
        return json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_LINE_TERMINATORS | JSON_THROW_ON_ERROR);
    }
}
