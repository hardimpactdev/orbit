<?php

declare(strict_types=1);

use App\Infrastructure\Metrics\MetricsConfigurationRenderer;

it('renders fleet configuration and keeps generated file content protected', function (): void {
    $bundle = new MetricsConfigurationRenderer()->render([
        ['name' => 'gateway', 'address' => '10.44.0.1'],
        ['name' => 'app-dev', 'address' => '10.44.0.3'],
    ], 'admin-password');
    $files = [];
    foreach ($bundle->files as $file) {
        $input = $file->contents->input();
        $files[$file->path] = stream_get_contents($input->stream());
        expect((string) $file->contents)->toBe('[PROTECTED]');
    }
    expect($files)->toHaveCount(8)
        ->and($files['/etc/orbit/metrics/prometheus.yml'])->toContain('gateway', 'app-dev')
        ->and($files['/etc/orbit/metrics/grafana/admin-password'])->toBe('admin-password')
        ->and($files['/etc/orbit/metrics/grafana/dashboards/orbit-node-resources.json'])->toContain('gateway', 'app-dev');
});

it('refuses to render without a Grafana admin password', function (): void {
    expect(fn () => new MetricsConfigurationRenderer()->render([], ''))->toThrow(InvalidArgumentException::class, 'admin password is unavailable');
});
