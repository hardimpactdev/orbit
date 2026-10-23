<?php

declare(strict_types=1);

use App\Domain\AppDev\RuntimeConvergenceException;
use App\Domain\Instances\Deployment\DeploymentEvent;
use App\Domain\Instances\Deployment\DeploymentOutputStream;
use App\Domain\Instances\Deployment\DeploymentPhase;
use App\Domain\Instances\Deployment\DeploymentRelease;
use App\Domain\Instances\Deployment\DeploymentRequest;
use App\Domain\Instances\Deployment\DeploymentStep;
use Tests\Support\FakeAgentCommandRunner;

// A deploy step runs as `instances.run-deploy-step`. The agent's Rust tests prove how it runs the program, redacts
// each line, and publishes it on the operation's channel; this file checks what the Gateway sends and records.

it('runs a deploy step in the release and records its output in order', function (): void {
    [$deployment, $agents, $instance] = release_commands(fn (FakeAgentCommandRunner $agents) => $agents->answer('instances.run-deploy-step', [
        'exit_code' => 0,
        'output' => [['stream' => 'stdout', 'value' => "building\n"], ['stream' => 'stderr', 'value' => "warning\n"]],
        'truncated' => false,
    ]));
    $instance->environmentValues()->create(['env_key' => 'API_TOKEN', 'env_value' => 'secret-token-value']);
    $events = [];
    $request = new DeploymentRequest(output: static function (DeploymentEvent $event) use (&$events): void {
        $events[] = [$event->step, $event->stream, $event->value];
    }, operation: 42);
    $release = new DeploymentRelease('20260911-a1', '/home/orbit-app-1/releases/20260911-a1', str_repeat('a', 40));

    $result = $deployment->executeStep($instance->refresh(), $release, new DeploymentStep('migrate', DeploymentPhase::BeforeActivation, 'php artisan migrate --force', 60), $request);

    $input = $agents->calls[0]['input'];
    expect($result->stdout)->toBe("building\n")
        ->and($result->stderr)->toBe("warning\n")
        ->and($events)->toBe([['migrate', DeploymentOutputStream::Stdout, "building\n"], ['migrate', DeploymentOutputStream::Stderr, "warning\n"]])
        ->and(array_diff_key($input, ['script' => true]))->toBe([
            'user' => 'orbit-app-1', 'home' => '/home/orbit-app-1', 'release' => '20260911-a1',
            'operation' => 42, 'step' => 'migrate', 'secrets' => ['secret-token-value'],
        ])
        ->and($input['script'])->toContain('php artisan migrate --force');
});

it('fails the step with its exit code and output when the program fails', function (): void {
    [$deployment, , $instance] = release_commands(fn (FakeAgentCommandRunner $agents) => $agents->answer('instances.run-deploy-step', [
        'exit_code' => 3,
        'output' => [['stream' => 'stderr', 'value' => "boom\n"]],
        'truncated' => false,
    ]));
    $release = new DeploymentRelease('20260911-a1', '/home/orbit-app-1/releases/20260911-a1', str_repeat('a', 40));

    expect(fn () => $deployment->executeStep($instance, $release, new DeploymentStep('migrate', DeploymentPhase::BeforeActivation, 'php artisan migrate --force', 60), new DeploymentRequest))
        ->toThrow(function (RuntimeConvergenceException $exception): void {
            expect($exception->errorCode)->toBe('deployment.step_failed')
                ->and($exception->step)->toBe('deployment-step-migrate')
                ->and($exception->result?->exitCode)->toBe(3)
                ->and($exception->result?->stderr)->toBe("boom\n");
        });
});
