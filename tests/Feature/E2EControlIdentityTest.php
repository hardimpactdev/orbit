<?php

declare(strict_types=1);

use Illuminate\Contracts\Process\ProcessResult;
use Mockery as m;
use Tests\E2E\Support\E2EControlIdentity;
use Tests\E2E\Support\E2EInstance;
use Tests\E2E\Support\SshKeyPair;

afterEach(function (): void {
    m::close();
});

it('ensures the local control node identity over the control user transport', function (): void {
    $key = new SshKeyPair('/tmp/e2e-id', '/tmp/e2e-id.pub');

    $result = m::mock(ProcessResult::class);
    $result->shouldReceive('successful')->andReturn(true);
    $result->shouldReceive('exitCode')->andReturn(0);
    $result->shouldReceive('output')->andReturn('');
    $result->shouldReceive('errorOutput')->andReturn('');

    $control = m::mock(E2EInstance::class);
    $control->shouldReceive('name')->andReturn('control');
    $control->shouldReceive('ssh')
        ->once()
        ->with(
            'control',
            $key,
            m::on(fn (string $command): bool => str_contains($command, 'php artisan tinker --execute=')
                && str_contains($command, 'control-1')
                && str_contains($command, 'is_local')),
            60,
        )
        ->andReturn($result);

    E2EControlIdentity::ensure($control, 'control', $key);
});
