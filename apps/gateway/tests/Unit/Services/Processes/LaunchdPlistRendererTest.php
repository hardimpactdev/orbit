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
    $node = Node::factory()->create([
        'name' => 'mac-app',
        'platform' => 'macos_14',
        'user' => 'nckrtl',
    ]);
    if (! $node instanceof Node) {
        throw new RuntimeException('Node factory did not return a Node.');
    }

    $app = App::factory()->create([
        'name' => 'docs',
        'node_id' => $node->id,
        'path' => '/Users/nckrtl/apps/docs & api',
    ]);
    if (! $app instanceof App) {
        throw new RuntimeException('App factory did not return an App.');
    }

    $app->setRelation('node', $node);
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
        ->toBe('orbit_docs_main_feedback')
        ->and($renderer->label($runtimeUnit))
        ->toBe('dev.hardimpact.orbit.orbit_docs_main_feedback')
        ->and($renderer->plistPath($runtimeUnit, $node))
        ->toBe('/Users/nckrtl/Library/LaunchAgents/dev.hardimpact.orbit.orbit_docs_main_feedback.plist')
        ->and($renderer->stdoutLogPath($runtimeUnit, $node))
        ->toBe('/Users/nckrtl/Library/Logs/Orbit/processes/orbit_docs_main_feedback.out.log')
        ->and($renderer->stderrLogPath($runtimeUnit, $node))
        ->toBe('/Users/nckrtl/Library/Logs/Orbit/processes/orbit_docs_main_feedback.err.log')
        ->and($plist)
        ->toContain('<string>dev.hardimpact.orbit.orbit_docs_main_feedback</string>')
        ->toContain('<string>php artisan feedback:work --queue=&quot;high &amp; low&quot;</string>')
        ->toContain('<string>/Users/nckrtl/apps/docs &amp; api</string>')
        ->toContain('<key>KeepAlive</key>')
        ->toContain('<true/>')
        ->toContain('<key>PATH</key>')
        ->toContain('<key>HOME</key>')
        ->toContain('<key>ORBIT_PROCESS</key>');
});
