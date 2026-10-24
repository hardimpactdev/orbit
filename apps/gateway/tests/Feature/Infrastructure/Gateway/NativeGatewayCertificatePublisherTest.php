<?php

declare(strict_types=1);

use App\Domain\Certificates\GatewayCertificatePaths;
use App\Domain\Nodes\NodeProvisioningException;
use App\Infrastructure\AgentCommands\AgentCommandRefused;
use App\Infrastructure\AgentCommands\AgentRunnerFailure;
use App\Infrastructure\Gateway\NativeGatewayCertificatePublisher;
use App\Models\Node;
use Illuminate\Filesystem\Filesystem;
use Tests\Support\FakeAgentCommandRunner;

function certificate_publisher_inputs(): array
{
    $home = sys_get_temp_dir().'/orbit-certificate-publisher-'.bin2hex(random_bytes(8));
    mkdir($home.'/ca', 0700, true);
    file_put_contents($home.'/ca/root.pem', 'ROOT');
    file_put_contents($home.'/leaf.pem', 'LEAF');
    file_put_contents($home.'/leaf.key', 'SECRET KEY');

    return [$home, new GatewayCertificatePaths($home.'/leaf.key', $home.'/leaf.pem')];
}

it('sends the selected certificate generation and root in one command on each convergence', function (): void {
    [$home, $paths] = certificate_publisher_inputs();
    $agents = new FakeAgentCommandRunner()->answer('gateway.publish-certificate', ['changed' => false]);
    try {
        $publisher = new NativeGatewayCertificatePublisher($agents, $home);
        $node = new Node(['name' => 'gateway']);
        $publisher->publish($node, $paths);
        $publisher->publish($node, $paths);

        expect($agents->calls)->toBe(array_fill(0, 2, [
            'node' => 'gateway', 'command' => 'gateway.publish-certificate',
            'input' => ['certificate' => 'LEAF', 'key' => 'SECRET KEY', 'root_certificate' => 'ROOT'],
        ]));
    } finally {
        new Filesystem()->deleteDirectory($home);
    }
});

it('maps native refusals to provisioning steps and retains the underlying refusal', function (string $code, array $details, string $step, string $publicCode): void {
    [$home, $paths] = certificate_publisher_inputs();
    $agents = new FakeAgentCommandRunner()->refuse('gateway.publish-certificate', $code, $details);
    try {
        expect(fn () => new NativeGatewayCertificatePublisher($agents, $home)->publish(new Node(['name' => 'gateway']), $paths))
            ->toThrow(function (NodeProvisioningException $exception) use ($step, $publicCode, $code): void {
                expect($exception->step)->toBe('gateway-certificate-'.$step)
                    ->and($exception->errorCode)->toBe($publicCode)
                    ->and($exception->getPrevious())->toBeInstanceOf(AgentCommandRefused::class)
                    ->and($exception->result->stdout)->toContain($code)
                    ->and($exception->getMessage())->not->toContain('SECRET KEY');
            });
        expect($agents->commands())->toBe(['gateway.publish-certificate']);
    } finally {
        new Filesystem()->deleteDirectory($home);
    }
})->with([
    ['gateway.certificate_invalid', [], 'validate', 'gateway.certificate_invalid'],
    ['gateway.certificate_unsafe', [], 'publish', 'gateway.certificate_publish_failed'],
    ['gateway.certificate_failed', ['stage' => 'publish'], 'publish', 'gateway.certificate_publish_failed'],
    ['gateway.certificate_failed', ['stage' => 'stage'], 'stage', 'gateway.certificate_install_failed'],
    ['gateway.certificate_failed', ['stage' => 'read'], 'stage', 'gateway.certificate_install_failed'],
    ['gateway.certificate_failed', ['stage' => 'reload'], 'reload', 'gateway.caddy_start_failed'],
    ['gateway.certificate_recovery_failed', [], 'reload', 'gateway.caddy_start_failed'],
]);

it('refuses missing certificate inputs before calling the agent', function (string $missing): void {
    [$home, $paths] = certificate_publisher_inputs();
    $agents = new FakeAgentCommandRunner;
    unlink($home.'/'.$missing);
    try {
        expect(fn () => new NativeGatewayCertificatePublisher($agents, $home)->publish(new Node, $paths))
            ->toThrow(function (NodeProvisioningException $exception): void {
                expect($exception->step)->toBe('gateway-certificate-stage')
                    ->and($exception->errorCode)->toBe('gateway.certificate_install_failed');
            });
        expect($agents->calls)->toBe([]);
    } finally {
        new Filesystem()->deleteDirectory($home);
    }
})->with(['leaf.pem', 'leaf.key', 'ca/root.pem']);

it('retains transport failure without resending the publication', function (): void {
    [$home, $paths] = certificate_publisher_inputs();
    $failure = new AgentRunnerFailure('gateway.publish-certificate', AgentRunnerFailure::Transport, 'Connection lost.');
    $agents = new FakeAgentCommandRunner()->answer('gateway.publish-certificate', static fn (): array => throw $failure);
    try {
        expect(fn () => new NativeGatewayCertificatePublisher($agents, $home)->publish(new Node, $paths))
            ->toThrow(function (NodeProvisioningException $exception) use ($failure): void {
                expect($exception->getPrevious())->toBe($failure)
                    ->and($exception->errorCode)->toBe('gateway.certificate_publish_failed');
            });
        expect($agents->commands())->toBe(['gateway.publish-certificate']);
    } finally {
        new Filesystem()->deleteDirectory($home);
    }
});

it('keeps certificate publication behind the named command boundary', function (): void {
    expect(file_get_contents(app_path('Infrastructure/Gateway/NativeGatewayCertificatePublisher.php')))
        ->not->toContain('ProcessRunner', 'SshExecutor', 'ProcessInvocation', 'bash', 'sudo', 'openssl', '/etc/caddy');
});
