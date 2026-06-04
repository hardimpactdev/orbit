<?php

declare(strict_types=1);

use App\E2E\Support\E2EConfig;
use App\E2E\Support\IncusHost;
use App\E2E\Support\IncusInstance;
use App\E2E\Support\SshKeyPair;
use Illuminate\Contracts\Process\ProcessResult;
use Illuminate\Support\Facades\Process;
use Mockery as m;

afterEach(function (): void {
    m::close();
});

function incusInstanceResult(string $output = '', bool $successful = true): ProcessResult
{
    $result = m::mock(ProcessResult::class);
    $result->shouldReceive('successful')->andReturn($successful);
    $result->shouldReceive('output')->andReturn($output);
    $result->shouldReceive('errorOutput')->andReturn($successful ? '' : 'failed');

    return $result;
}

it('prefers the live guest IPv4 over stale Incus state', function (): void {
    $commands = [];
    $host = m::mock(IncusHost::class, [E2EConfig::fromEnvironment()])->makePartial();
    $host->shouldReceive('run')
        ->andReturnUsing(function (string $command, ?int $timeoutSeconds = null) use (&$commands): ProcessResult {
            $commands[] = $command;

            if (str_contains($command, 'ip -j -4 address show scope global')) {
                return incusInstanceResult("10.231.7.248\n");
            }

            if (str_contains($command, '/1.0/instances/orbit-e2e-gateway/state')) {
                return incusInstanceResult("10.231.7.249\n");
            }

            return incusInstanceResult();
        });

    $instance = new IncusInstance($host, 'orbit-e2e-gateway', commandTransport: true);

    expect($instance->waitForIpv4())->toBe('10.231.7.248')
        ->and(implode("\n", $commands))->not->toContain('/1.0/instances/orbit-e2e-gateway/state');
});

it('runs authenticated Incus command transport scripts from a guest file', function (): void {
    withE2EEnvironment(['GH_TOKEN', 'GITHUB_TOKEN', 'ORBIT_E2E_HOST'], [
        'GH_TOKEN' => 'test-token',
        'GITHUB_TOKEN' => null,
        'ORBIT_E2E_HOST' => 'beast',
    ], function (): void {
        $commands = [];
        $localCopies = [];

        Process::fake(function ($process) use (&$localCopies) {
            $localCopies[] = (string) $process->command;

            return Process::result();
        });

        $host = m::mock(IncusHost::class, [E2EConfig::fromEnvironment()])->makePartial();
        $host->shouldReceive('runWithInput')->never();
        $host->shouldReceive('run')
            ->andReturnUsing(function (string $command, ?int $timeoutSeconds = null) use (&$commands): ProcessResult {
                $commands[] = $command;

                return incusInstanceResult();
            });

        $instance = new IncusInstance($host, 'orbit-e2e-dev', commandTransport: true);

        $instance->ssh(
            'orbit',
            new SshKeyPair('/tmp/orbit-test-key', '/tmp/orbit-test-key.pub'),
            'gh auth status',
            timeoutSeconds: 30,
        );

        $joined = implode("\n", $commands);

        expect($joined)
            ->toContain('incus file push ')
            ->toContain('/tmp/orbit-e2e-github-auth-')
            ->toContain('incus exec '.escapeshellarg('orbit-e2e-dev').' -- chown '.escapeshellarg('orbit'))
            ->toContain('incus exec '.escapeshellarg('orbit-e2e-dev').' -- chmod 700 ')
            ->toContain('incus exec '.escapeshellarg('orbit-e2e-dev').' -- runuser -u '.escapeshellarg('orbit').' -- bash ')
            ->toContain('</dev/null')
            ->not->toContain('bash -s')
            ->and(implode("\n", $localCopies))
            ->toContain('scp -o BatchMode=yes')
            ->toContain("'beast':'/tmp/orbit-current-transfer-");
    });
});
