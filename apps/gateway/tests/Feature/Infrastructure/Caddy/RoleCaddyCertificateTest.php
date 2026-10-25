<?php

declare(strict_types=1);

use App\Infrastructure\AgentCommands\AgentRunnerFailure;
use App\Infrastructure\Caddy\RoleCaddyCertificate;
use App\Models\Node;
use Tests\Support\FakeAgentCommandRunner;

it('sends role and extension certificate data through the shared publisher', function (string $scope, string $name): void {
    $node = new Node(['name' => 'app-dev']);
    $agents = new FakeAgentCommandRunner()->answer('caddy.install-certificate', ['changed' => true])->answer('caddy.remove-certificate', []);
    $publisher = new RoleCaddyCertificate($agents);
    $directory = '/etc/caddy/orbit-'.$scope.'-cert-current';

    expect($publisher->publish($node, $directory, $name, 'caddy', 'leaf', 'private')->succeeded())->toBeTrue();
    expect($publisher->remove($node, $directory)->succeeded())->toBeTrue();
    expect($agents->calls)->toBe([
        ['node' => 'app-dev', 'command' => 'caddy.install-certificate', 'input' => [
            'directory' => $directory, 'certificate_file' => $name.'.pem', 'key_file' => $name.'.key',
            'certificate' => 'leaf', 'key' => 'private', 'service' => 'caddy',
        ]],
        ['node' => 'app-dev', 'command' => 'caddy.remove-certificate', 'input' => ['directory' => $directory]],
    ]);
})->with([['websocket', 'reverb'], ['analytics', 'analytics'], ['ai-router', 'ai_router'], ['proxycli', 'proxycli']]);

it('keeps the native recovery path available in a failed publication result', function (): void {
    $directory = '/etc/caddy/orbit-analytics-cert-current.candidate.0123456789abcdef';
    $agents = new FakeAgentCommandRunner()->refuse('caddy.install-certificate', 'caddy.certificate_recovery_failed', ['directory' => $directory]);

    $result = new RoleCaddyCertificate($agents)->publish(new Node(['name' => 'app-dev']), '/etc/caddy/orbit-analytics-cert-current', 'analytics', 'caddy', 'leaf', 'private');

    expect($result->succeeded())->toBeFalse()
        ->and(json_decode($result->stdout, true, flags: JSON_THROW_ON_ERROR))->toBe(['code' => 'caddy.certificate_recovery_failed', 'details' => ['directory' => $directory]])
        ->and($result->stderr)->toContain('caddy.certificate_recovery_failed')->not->toContain('private');
});

it('returns transport failures without hiding a lost certificate publication', function (): void {
    $agents = new FakeAgentCommandRunner()->answer('caddy.remove-certificate', static fn (): array => throw new AgentRunnerFailure('caddy.remove-certificate', AgentRunnerFailure::Transport, 'Connection lost'));

    $result = new RoleCaddyCertificate($agents)->remove(new Node(['name' => 'app-dev']), '/etc/caddy/orbit-analytics-cert-current');

    expect($result->exitCode)->toBe(255)->and($result->stderr)->toBe('Connection lost');
});
