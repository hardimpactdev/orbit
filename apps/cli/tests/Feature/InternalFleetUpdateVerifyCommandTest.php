<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Artisan;
use Orbit\Core\Http\JsonEnvelope;
use Orbit\Core\Security\OperationTokenSigner;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;

describe('internal fleet update verify command', function (): void {
    beforeEach(function (): void {
        app()->forgetInstance('App\Services\Executor\OperationTokenGuard');
        fakeGateway(fakeSuccessEnvelope([
            'allowed' => true,
        ]));
        $originalPath = getenv('PATH');
        $_ENV['ORBIT_FLEET_UPDATE_VERIFY_ORIGINAL_PATH'] = $originalPath === false ? '' : $originalPath;
    });

    afterEach(function (): void {
        putenv('PATH='.($_ENV['ORBIT_FLEET_UPDATE_VERIFY_ORIGINAL_PATH'] ?? ''));

        $fakeBinPaths = glob(sys_get_temp_dir().'/orbit-fleet-update-verify-bin-*');

        if ($fakeBinPaths === false) {
            return;
        }

        foreach ($fakeBinPaths as $dir) {
            delete_fleet_update_verify_fake_bin($dir);
        }
    });

    it('rejects a missing operation token before running verification checks', function (): void {
        [$exitCode, $output] = run_internal_fleet_update_verify_command([
            'check' => 'cli',
            '--json' => true,
        ]);

        expect($exitCode)
            ->toBe(1)
            ->and(json_decode($output, associative: true, flags: JSON_THROW_ON_ERROR))
            ->toBe(JsonEnvelope::failure(
                'missing_token',
                'Operation token is required.',
            ));
    });

    it('rejects invalid check names after token validation', function (): void {
        [$exitCode, $output] = run_internal_fleet_update_verify_command([
            'check' => 'shell',
            '--operation-token' => fleet_update_verify_signed_operation_token(),
            '--json' => true,
        ]);

        expect($exitCode)
            ->toBe(1)
            ->and(json_decode($output, associative: true, flags: JSON_THROW_ON_ERROR))
            ->toBe(JsonEnvelope::failure(
                'validation_failed',
                'Fleet update verification check is invalid.',
                ['field' => 'check'],
            ));
    });

    it('verifies the local Orbit CLI through fixed argv', function (): void {
        $bin = install_fleet_update_verify_fake_bin('orbit', output: "Orbit 1.2.3\n");

        [$exitCode, $output] = run_internal_fleet_update_verify_command([
            'check' => 'cli',
            '--operation-token' => fleet_update_verify_signed_operation_token(),
            '--json' => true,
        ]);
        $data = fleet_update_verify_success_data($output);

        expect($exitCode)
            ->toBe(0)
            ->and($data)
            ->toMatchArray([
                'check' => 'cli',
                'verified' => true,
                'version' => 'Orbit 1.2.3',
            ])
            ->and(file_get_contents("{$bin}/calls.log"))
            ->toContain('orbit --version --local');
    });

    it('verifies required role images through fixed Docker argv', function (): void {
        $bin = install_fleet_update_verify_fake_bin('docker', output: "[]\n");

        [$exitCode, $output] = run_internal_fleet_update_verify_command(
            [
                'check' => 'role-images',
                '--operation-token' => fleet_update_verify_signed_operation_token(),
                '--json' => true,
            ],
            stdin: json_encode([
                'images' => ['caddy:2-alpine', 'hardimpact/orbit-reverb:1.2.3'],
            ], JSON_THROW_ON_ERROR),
        );
        $data = fleet_update_verify_success_data($output);

        expect($exitCode)
            ->toBe(0)
            ->and($data)
            ->toMatchArray([
                'check' => 'role-images',
                'verified' => true,
                'images' => ['caddy:2-alpine', 'hardimpact/orbit-reverb:1.2.3'],
            ])
            ->and(file_get_contents("{$bin}/calls.log"))
            ->toContain('docker image inspect caddy:2-alpine')
            ->toContain('docker image inspect hardimpact/orbit-reverb:1.2.3');
    });
});

function fleet_update_verify_signed_operation_token(
    string $id = 'fleet-update-verify',
    string $node = 'app-dev',
    string $command = 'internal:fleet-update:verify',
    ?int $issuedAt = null,
    ?int $expiresAt = null,
): string {
    $issuedAt ??= time() - 10;
    $expiresAt ??= time() + 120;

    return new OperationTokenSigner()
        ->sign(
            secret: fleet_update_verify_operation_secret(),
            id: $id,
            node: $node,
            command: $command,
            issuedAt: $issuedAt,
            expiresAt: $expiresAt,
        )
        ->toString();
}

function fleet_update_verify_operation_secret(): string
{
    return implode('-', ['gateway', 'secret']);
}

/**
 * @param  array<string, mixed>  $parameters
 * @return array{int, string}
 */
function run_internal_fleet_update_verify_command(array $parameters, string $stdin = ''): array
{
    $stream = fopen(filename: 'php://temp', mode: 'r+');
    fwrite($stream, $stdin);
    rewind($stream);

    $input = new ArrayInput($parameters);
    $input->setStream($stream);

    $output = new BufferedOutput;
    /** @var Command|null $command */
    $command = Artisan::all()['internal:fleet-update:verify'] ?? null;

    expect($command)->toBeInstanceOf(Command::class);

    $exitCode = $command instanceof Command ? $command->run($input, $output) : 1;

    return [$exitCode, trim($output->fetch())];
}

/**
 * @return array<string, mixed>
 */
function fleet_update_verify_success_data(string $output): array
{
    /** @var mixed $payload */
    $payload = json_decode($output, associative: true, flags: JSON_THROW_ON_ERROR);

    if (! is_array($payload)) {
        return [];
    }

    /** @var mixed $success */
    $success = $payload['success'] ?? null;

    if (! is_array($success)) {
        return [];
    }

    /** @var mixed $data */
    $data = $success['data'] ?? null;

    if (! is_array($data)) {
        return [];
    }

    foreach (array_keys($data) as $key) {
        if (! is_string($key)) {
            return [];
        }
    }

    /** @var array<string, mixed> $data */
    return $data;
}

function install_fleet_update_verify_fake_bin(string $binary, string $output, int $exitCode = 0): string
{
    $dir = sys_get_temp_dir().'/orbit-fleet-update-verify-bin-'.bin2hex(random_bytes(8));
    mkdir($dir);
    $outputPath = "{$dir}/output";
    file_put_contents($outputPath, $output);

    file_put_contents("{$dir}/{$binary}", <<<PHP
        #!/usr/bin/env php
        <?php
        file_put_contents(__DIR__.'/calls.log', basename(\$argv[0]).' '.implode(' ', array_slice(\$argv, 1)).PHP_EOL, FILE_APPEND);
        echo file_get_contents('{$outputPath}');
        exit({$exitCode});
        PHP);
    chmod(filename: "{$dir}/{$binary}", permissions: 0o755);

    $path = getenv('PATH');
    putenv("PATH={$dir}:".($path === false ? '' : $path));

    return $dir;
}

function delete_fleet_update_verify_fake_bin(string $path): void
{
    delete_fleet_update_verify_file("{$path}/orbit");
    delete_fleet_update_verify_file("{$path}/docker");
    delete_fleet_update_verify_file("{$path}/calls.log");
    delete_fleet_update_verify_file("{$path}/output");

    if (is_dir($path)) {
        rmdir($path);
    }
}

function delete_fleet_update_verify_file(string $path): void
{
    if (! is_file($path)) {
        return;
    }

    unlink($path);
}
