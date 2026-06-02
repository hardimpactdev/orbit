<?php

declare(strict_types=1);

use App\E2E\Support\E2EInstance;
use App\E2E\Support\E2EOperatorIdentity;
use App\E2E\Support\SshKeyPair;
use Illuminate\Contracts\Process\ProcessResult;
use Mockery as m;

afterEach(function (): void {
    m::close();
});

it('removes the stale local operator identity over the operator node transport', function (): void {
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
            m::on(fn (string $command): bool => str_contains($command, 'php -r ')
                && str_contains($command, '/home/orbit/.config/orbit/gateway.sqlite')
                && str_contains($command, 'new PDO')
                && ! str_contains($command, 'php artisan tinker')
                && ! str_contains($command, 'php artisan')
                && ! str_contains($command, 'apps/gateway/artisan')
                && str_contains($command, 'operator-1')
                && str_contains($command, 'DELETE FROM nodes WHERE name = :name')),
            60,
        )
        ->andReturn($result);

    E2EOperatorIdentity::ensure($operator, 'orbit', $key);
});
