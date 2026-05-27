<?php

declare(strict_types=1);

use App\E2E\Support\E2EControlIdentity;
use App\E2E\Support\E2EInstance;
use App\E2E\Support\SshKeyPair;
use Illuminate\Contracts\Process\ProcessResult;
use Mockery as m;

afterEach(function (): void {
    m::close();
});

it('removes the obsolete local control identity over the operator node transport', function (): void {
    $key = new SshKeyPair('/tmp/e2e-id', '/tmp/e2e-id.pub');

    $result = m::mock(ProcessResult::class);
    $result->shouldReceive('successful')->andReturn(true);
    $result->shouldReceive('exitCode')->andReturn(0);
    $result->shouldReceive('output')->andReturn('');
    $result->shouldReceive('errorOutput')->andReturn('');

    $operator = m::mock(E2EInstance::class);
    $operator->shouldReceive('name')->andReturn('operator');
    $operator->shouldReceive('ssh')
        ->once()
        ->with(
            'orbit',
            $key,
            m::on(fn (string $command): bool => str_contains($command, 'php apps/gateway/artisan tinker --execute=')
                && ! str_contains($command, 'orbit tinker')
                && ! str_contains($command, 'php artisan')
                && str_contains($command, 'control-1')
                && str_contains($command, 'role')
                && str_contains($command, 'control')
                && str_contains($command, 'delete()')),
            60,
        )
        ->andReturn($result);

    E2EControlIdentity::ensure($operator, 'orbit', $key);
});
