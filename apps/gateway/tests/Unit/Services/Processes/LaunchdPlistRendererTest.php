<?php

declare(strict_types=1);

use App\Enums\ProcessRestartPolicy;
use App\Models\App;
use App\Models\Node;
use App\Models\Process as OrbitProcess;
use App\Services\Processes\LaunchdPlistRenderer;
use Database\Factories\ProcessFactory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

it('renders user LaunchAgent plist content with Orbit labels logs and escaped values', function (): void {
    $node = Node::factory()
        ->appDev()
        ->create([
            'name' => 'mac-app',
            'platform' => 'macos_14',
            'user' => 'nckrtl',
        ]);
    if (! $node instanceof Node) {
        throw new RuntimeException('Node factory did not return a Node.');
    }

    $app = App::factory()
        ->placedOn($node, 'development', '/Users/nckrtl/apps/docs & api')
        ->create([
            'name' => 'docs',
        ]);
    if (! $app instanceof App) {
        throw new RuntimeException('App factory did not return an App.');
    }

    $factory = OrbitProcess::factory();
    if (! $factory instanceof ProcessFactory) {
        throw new RuntimeException('Process factory did not return a ProcessFactory.');
    }

    $process = $factory
        ->forOwner($app)
        ->create([
            'name' => 'feedback',
            'command' => 'php artisan feedback:work --queue="high & low"',
            'restart_policy' => ProcessRestartPolicy::Always,
        ]);
    if (! $process instanceof OrbitProcess) {
        throw new RuntimeException('Process factory did not return a Process.');
    }

    $renderer = app(LaunchdPlistRenderer::class);
    $runtimeUnit = $renderer->unitName($app, $process);
    $plist = $renderer->render($node, $app, $process);

    expect($runtimeUnit)
        ->toBe('orbit_docs_development_main_feedback')
        ->and($renderer->label($runtimeUnit))
        ->toBe('dev.hardimpact.orbit.orbit_docs_development_main_feedback')
        ->and($renderer->plistPath($runtimeUnit, $node))
        ->toBe('/Users/nckrtl/Library/LaunchAgents/dev.hardimpact.orbit.orbit_docs_development_main_feedback.plist')
        ->and($renderer->stdoutLogPath($runtimeUnit, $node))
        ->toBe('/Users/nckrtl/Library/Logs/Orbit/processes/orbit_docs_development_main_feedback.out.log')
        ->and($renderer->stderrLogPath($runtimeUnit, $node))
        ->toBe('/Users/nckrtl/Library/Logs/Orbit/processes/orbit_docs_development_main_feedback.err.log')
        ->and($plist)
        ->toContain('<string>dev.hardimpact.orbit.orbit_docs_development_main_feedback</string>')
        ->toContain('<string>php artisan feedback:work --queue=&quot;high &amp; low&quot;</string>')
        ->toContain('<string>/Users/nckrtl/apps/docs &amp; api</string>')
        ->toContain('<key>KeepAlive</key>')
        ->toContain('<true/>')
        ->toContain("<key>RunAtLoad</key>\n    <false/>")
        ->toContain('<key>PATH</key>')
        ->toContain('/Users/nckrtl/.vite-plus/bin')
        ->toContain('<key>HOME</key>')
        ->toContain('<key>ORBIT_PROCESS</key>');
});

it('keeps node-owned launch agents configured to run at load', function (): void {
    $node = Node::factory()
        ->appDev()
        ->create([
            'name' => 'mac-app',
            'platform' => 'macos_14',
            'user' => 'nckrtl',
        ]);
    $app = App::factory()
        ->placedOn($node, 'development', '/Users/nckrtl/apps/docs')
        ->create([
            'name' => 'docs',
        ]);
    $process = OrbitProcess::factory()
        ->forOwner($node)
        ->create([
            'name' => 'opencode-server',
            'restart_policy' => ProcessRestartPolicy::Always,
        ]);

    expect(app(LaunchdPlistRenderer::class)->render($node, $app, $process))
        ->toContain("<key>RunAtLoad</key>\n    <true/>");
});

it('renders only PATH and HOME for node-owned launchd processes', function (): void {
    $node = Node::factory()->create([
        'name' => 'gateway',
        'platform' => 'macos_14',
        'user' => 'orbit',
        'tld' => 'gateway',
        'status' => 'active',
    ]);
    $surrogateApp = App::factory()
        ->placedOn($node, 'development', '/Users/orbit')
        ->create([
            'name' => 'gateway',
        ]);
    $process = OrbitProcess::factory()
        ->forOwner($node)
        ->create([
            'name' => 'node-exporter',
            'command' => '/usr/local/bin/node_exporter --web.listen-address=0.0.0.0:9100',
            'restart_policy' => ProcessRestartPolicy::Always,
        ]);

    $plist = app(LaunchdPlistRenderer::class)->render($node, $surrogateApp, $process);

    expect($plist)
        ->toContain('<key>PATH</key>')
        ->toContain('<key>HOME</key>')
        ->toContain('<string>/Users/orbit</string>')
        ->not->toContain('<key>APP_URL</key>')
        ->not->toContain('<key>VITE_APP_URL</key>')
        ->not->toContain('<key>VITE_VALET_HOST</key>')
        ->not->toContain('<key>VITE_DEV_SERVER_KEY</key>')
        ->not->toContain('<key>VITE_DEV_SERVER_CERT</key>')
        ->not->toContain('https://gateway')
        ->not->toContain('gateway.gateway');
});

it('still renders Laravel Vite URL and TLS variables for app-owned launchd processes', function (): void {
    $node = Node::factory()->create([
        'name' => 'mac-app',
        'platform' => 'macos_14',
        'user' => 'nckrtl',
        'tld' => 'test',
        'status' => 'active',
    ]);
    $app = App::factory()
        ->placedOn($node, 'development', '/Users/nckrtl/apps/docs', 'public', 'docs.test')
        ->create([
            'name' => 'docs',
        ]);
    $process = OrbitProcess::factory()
        ->forOwner($app)
        ->create([
            'name' => 'vite',
            'command' => 'npm run dev -- --host=0.0.0.0',
            'restart_policy' => ProcessRestartPolicy::Always,
        ]);

    $plist = app(LaunchdPlistRenderer::class)->render($node, $app, $process);

    expect($plist)
        ->toContain('<key>PATH</key>')
        ->toContain('<key>HOME</key>')
        ->toContain('<key>APP_URL</key>')
        ->toContain('<key>VITE_APP_URL</key>')
        ->toContain('<key>VITE_VALET_HOST</key>')
        ->toContain('<key>VITE_DEV_SERVER_KEY</key>')
        ->toContain('<key>VITE_DEV_SERVER_CERT</key>');
});

it('keeps app-prod launch agents configured to run at load', function (): void {
    $node = Node::factory()
        ->appProd()
        ->create([
            'name' => 'mac-production',
            'platform' => 'macos_14',
            'user' => 'nckrtl',
        ]);
    $app = App::factory()
        ->placedOn($node, 'development', '/Users/nckrtl/apps/docs')
        ->create([
            'name' => 'docs',
        ]);
    $process = OrbitProcess::factory()
        ->forOwner($app)
        ->create([
            'name' => 'queue',
            'restart_policy' => ProcessRestartPolicy::Always,
        ]);

    expect(app(LaunchdPlistRenderer::class)->render($node, $app, $process))
        ->toContain("<key>RunAtLoad</key>\n    <true/>");
});

it('renders Vite launch agents with dynamic host and certificate environment', function (): void {
    $node = Node::factory()->create([
        'name' => 'mac-app',
        'platform' => 'darwin',
        'user' => 'nckrtl',
        'tld' => 'nmbp',
    ]);
    if (! $node instanceof Node) {
        throw new RuntimeException('Node factory did not return a Node.');
    }

    $app = App::factory()
        ->placedOn($node, 'development', '/Users/nckrtl/apps/happie', 'public', 'happie.nmbp')
        ->create([
            'name' => 'happie-nmbp',
        ]);
    if (! $app instanceof App) {
        throw new RuntimeException('App factory did not return an App.');
    }

    $factory = OrbitProcess::factory();
    if (! $factory instanceof ProcessFactory) {
        throw new RuntimeException('Process factory did not return a ProcessFactory.');
    }

    $process = $factory
        ->forOwner($app)
        ->create([
            'name' => 'vite',
            'command' => 'vp dev --host "$VITE_VALET_HOST"',
            'restart_policy' => ProcessRestartPolicy::Always,
        ]);
    if (! $process instanceof OrbitProcess) {
        throw new RuntimeException('Process factory did not return a Process.');
    }

    $plist = app(LaunchdPlistRenderer::class)->render($node, $app, $process);

    expect($plist)
        ->toContain('<string>vp dev --host &quot;$VITE_VALET_HOST&quot;</string>')
        ->toContain('<key>PATH</key>')
        ->toContain('/Users/nckrtl/.vite-plus/bin')
        ->toContain('<key>APP_URL</key>')
        ->toContain('<string>https://happie.nmbp</string>')
        ->toContain('<key>VITE_VALET_HOST</key>')
        ->toContain('<string>happie.nmbp</string>')
        ->toContain('<key>VITE_DEV_SERVER_KEY</key>')
        ->toContain('<string>/Users/nckrtl/.config/orbit/certs/happie.nmbp.key</string>')
        ->toContain('<key>VITE_DEV_SERVER_CERT</key>')
        ->toContain('<string>/Users/nckrtl/.config/orbit/certs/happie.nmbp.crt</string>');
});
