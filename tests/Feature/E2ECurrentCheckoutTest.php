<?php

declare(strict_types=1);

use Illuminate\Contracts\Process\ProcessResult;
use Illuminate\Support\Facades\Process;
use Mockery as m;
use Tests\E2E\Support\E2ECurrentCheckout;
use Tests\E2E\Support\E2EInstance;
use Tests\E2E\Support\E2ETopologyKind;
use Tests\E2E\Support\E2ETopologyLease;
use Tests\E2E\Support\SshKeyPair;

afterEach(function (): void {
    E2ECurrentCheckout::flushCache();
    m::close();
});

function currentCheckoutProcessResult(bool $successful = true): ProcessResult
{
    $result = m::mock(ProcessResult::class);
    $result->shouldReceive('successful')->andReturn($successful);
    $result->shouldReceive('output')->andReturn('');
    $result->shouldReceive('errorOutput')->andReturn('');

    return $result;
}

function currentCheckoutFakeInstance(array &$commands, string $name = 'fake-control'): E2EInstance
{
    return new class($commands, $name) implements E2EInstance
    {
        /**
         * @param  array<int, string>  $commands
         */
        public function __construct(
            private array &$commands,
            private readonly string $name,
        ) {}

        public function name(): string
        {
            return $this->name;
        }

        public function exec(string $command, ?int $timeoutSeconds = null): ProcessResult
        {
            $this->commands[] = $command;

            return currentCheckoutProcessResult();
        }

        public function ssh(string $user, SshKeyPair $keyPair, string $command, ?int $timeoutSeconds = null): ProcessResult
        {
            $this->commands[] = $command;

            return currentCheckoutProcessResult();
        }

        public function authorizeSsh(string $user, SshKeyPair $keyPair): void {}

        public function copyFileToInstance(string $sourcePath, string $targetPath): void
        {
            $this->commands[] = "copy {$sourcePath} {$targetPath}";
        }

        public function waitForIpv4(): string
        {
            return '10.201.0.10';
        }

        public function waitForSsh(string $user, SshKeyPair $keyPair): void {}

        public function delete(): void {}
    };
}

it('reuses the prepared checkout vendor directory when composer lock has not changed', function (): void {
    Process::fake([
        'COPYFILE_DISABLE=1 tar *' => Process::result(),
    ]);

    $commands = [];
    $instance = currentCheckoutFakeInstance($commands);
    $key = new SshKeyPair('/tmp/id_ed25519', '/tmp/id_ed25519.pub');

    E2ECurrentCheckout::install($instance, 'control', $key);

    $commandOutput = implode("\n", $commands);

    expect($commandOutput)->toContain("cmp -s '/home/control/orbit/composer.lock' composer.lock")
        ->and($commandOutput)->toContain("[ -d '/home/control/orbit/vendor/laravel/boost' ]")
        ->and($commandOutput)->toContain("cp -al '/home/control/orbit/vendor' vendor")
        ->and($commandOutput)->toContain('composer install --no-interaction --prefer-dist --optimize-autoloader');
});

it('can seed the current checkout from prepared topology state', function (): void {
    Process::fake([
        'COPYFILE_DISABLE=1 tar *' => Process::result(),
    ]);

    $commands = [];
    $instance = currentCheckoutFakeInstance($commands);
    $key = new SshKeyPair('/tmp/id_ed25519', '/tmp/id_ed25519.pub');

    E2ECurrentCheckout::install($instance, 'control', $key, seedFrom: '/home/control/orbit');

    $commandOutput = implode("\n", $commands);

    expect($commandOutput)->toContain("cp '/home/control/orbit/.env' .env")
        ->and($commandOutput)->toContain("cp '/home/control/orbit/database/database.sqlite' database/database.sqlite")
        ->and($commandOutput)->toContain("cp -a '/home/control/orbit/storage/app' storage/app");
});

it('can cache the checkout install and clone isolated runtime paths', function (): void {
    $previous = getenv('ORBIT_E2E_CHECKOUT_CACHE');
    putenv('ORBIT_E2E_CHECKOUT_CACHE=process');

    Process::fake([
        'COPYFILE_DISABLE=1 tar *' => Process::result(),
    ]);

    $commands = [];
    $instance = currentCheckoutFakeInstance($commands);
    $key = new SshKeyPair('/tmp/id_ed25519', '/tmp/id_ed25519.pub');

    try {
        $firstPath = E2ECurrentCheckout::install($instance, 'control', $key, seedFrom: '/home/control/orbit');
        $secondPath = E2ECurrentCheckout::install($instance, 'control', $key, seedFrom: '/home/control/orbit');

        $commandOutput = implode("\n", $commands);

        expect($firstPath)->toStartWith('/home/control/orbit-current-')
            ->and($secondPath)->toStartWith('/home/control/orbit-current-')
            ->and($secondPath)->not->toBe($firstPath)
            ->and(substr_count($commandOutput, 'tar --warning=no-unknown-keyword'))->toBe(1)
            ->and(substr_count($commandOutput, "cp -al '/home/control/orbit-current-base-"))->toBe(2)
            ->and(substr_count($commandOutput, 'copy '))->toBe(1)
            ->and($commandOutput)->toContain("rm -rf '{$firstPath}'/database '{$firstPath}'/storage '{$firstPath}'/.env")
            ->and($commandOutput)->toContain("rm -rf '{$secondPath}'/database '{$secondPath}'/storage '{$secondPath}'/.env");
    } finally {
        if ($previous === false) {
            putenv('ORBIT_E2E_CHECKOUT_CACHE');
        } else {
            putenv("ORBIT_E2E_CHECKOUT_CACHE={$previous}");
        }
    }
});

it('can install the current checkout on selected topology roles', function (): void {
    Process::fake([
        'COPYFILE_DISABLE=1 tar *' => Process::result(),
    ]);

    $controlCommands = [];
    $gatewayCommands = [];
    $devCommands = [];
    $prodCommands = [];

    $key = new SshKeyPair('/tmp/id_ed25519', '/tmp/id_ed25519.pub');
    $topology = new E2ETopologyLease(
        kind: E2ETopologyKind::ControlGatewayDevProd,
        control: currentCheckoutFakeInstance($controlCommands, 'control'),
        gateway: currentCheckoutFakeInstance($gatewayCommands, 'gateway'),
        dev: currentCheckoutFakeInstance($devCommands, 'dev'),
        prod: currentCheckoutFakeInstance($prodCommands, 'prod'),
        sshKeyPair: $key,
        rebuild: fn () => throw new RuntimeException('not expected'),
    );

    $paths = E2ECurrentCheckout::installOnTopology($topology, roles: ['control', 'gateway', 'dev', 'prod']);

    expect($paths)->toBe([
        'control' => '/home/control/orbit-current',
        'gateway' => '/home/orbit/orbit-current',
        'dev' => '/home/orbit/orbit-current',
        'prod' => '/home/orbit/orbit-current',
    ]);

    expect(implode("\n", $controlCommands))->toContain("cp '/home/control/orbit/.env' .env");
    expect(implode("\n", $gatewayCommands))->toContain("cp '/home/orbit/orbit/.env' .env");
    expect(implode("\n", $devCommands))->toContain("cp '/home/orbit/orbit/.env' .env");
    expect(implode("\n", $prodCommands))->toContain("cp '/home/orbit/orbit/.env' .env");
});
