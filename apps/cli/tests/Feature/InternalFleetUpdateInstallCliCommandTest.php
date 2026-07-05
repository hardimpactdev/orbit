<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Artisan;
use Orbit\Core\Http\JsonEnvelope;
use Orbit\Core\Security\OperationTokenSigner;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;

describe('internal fleet update install cli command', function (): void {
    beforeEach(function (): void {
        app()->forgetInstance('App\Services\Executor\OperationTokenGuard');
        fakeGateway(fakeSuccessEnvelope([
            'allowed' => true,
        ]));
        $originalPath = getenv('PATH');
        $_ENV['ORBIT_FLEET_UPDATE_INSTALL_CLI_ORIGINAL_PATH'] = $originalPath === false ? '' : $originalPath;
    });

    afterEach(function (): void {
        $path = $_ENV['ORBIT_FLEET_UPDATE_INSTALL_CLI_ORIGINAL_PATH'] ?? '';

        putenv('PATH='.$path);
        $_ENV['PATH'] = $path;
        $_SERVER['PATH'] = $path;
    });

    it('rejects a missing operation token before reading the install payload', function (): void {
        [$exitCode, $output] = run_internal_fleet_update_install_cli_command([
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

    it('installs a downloaded CLI artifact into typed local paths', function (): void {
        $workspace = make_fleet_update_install_cli_workspace();
        $artifactPath = "{$workspace}/artifact/orbit";
        $sha256 = hash_file('sha256', $artifactPath);

        [$exitCode, $output] = run_internal_fleet_update_install_cli_command(
            [
                '--operation-token' => fleet_update_install_cli_signed_operation_token(),
                '--json' => true,
            ],
            stdin: json_encode([
                'artifact_url' => "file://{$artifactPath}",
                'sha256' => $sha256,
                'install_root' => "{$workspace}/install-root",
                'bin_path' => "{$workspace}/bin/orbit",
                'shared_binary_path' => null,
                'role_images' => [],
            ], JSON_THROW_ON_ERROR),
        );
        $data = fleet_update_install_cli_success_data($output);

        expect($exitCode)
            ->toBe(0)
            ->and($data)
            ->toMatchArray([
                'installed' => true,
                'bin_path' => "{$workspace}/bin/orbit",
                'install_root' => "{$workspace}/install-root",
                'role_images' => [],
            ])
            ->and(is_link("{$workspace}/bin/orbit"))
            ->toBeTrue()
            ->and(hash_file('sha256', "{$workspace}/install-root/bin/orbit-binary"))
            ->toBe($sha256)
            ->and(shell_exec(escapeshellarg("{$workspace}/bin/orbit").' --version --local'))
            ->toBe("Orbit 9.9.9\n");
    });
});

function fleet_update_install_cli_signed_operation_token(
    string $id = 'fleet-update-install-cli',
    string $node = 'app-dev',
    string $command = 'internal:fleet-update:install-cli',
    ?int $issuedAt = null,
    ?int $expiresAt = null,
): string {
    $issuedAt ??= time() - 10;
    $expiresAt ??= time() + 120;

    return new OperationTokenSigner()
        ->sign(
            secret: fleet_update_install_cli_operation_secret(),
            id: $id,
            node: $node,
            command: $command,
            issuedAt: $issuedAt,
            expiresAt: $expiresAt,
        )
        ->toString();
}

function fleet_update_install_cli_operation_secret(): string
{
    return implode('-', ['gateway', 'secret']);
}

/**
 * @param  array<string, mixed>  $parameters
 * @return array{int, string}
 */
function run_internal_fleet_update_install_cli_command(array $parameters, string $stdin = ''): array
{
    $stream = fopen(filename: 'php://temp', mode: 'r+');
    fwrite($stream, $stdin);
    rewind($stream);

    $input = new ArrayInput($parameters);
    $input->setStream($stream);

    $output = new BufferedOutput;
    /** @var Command|null $command */
    $command = Artisan::all()['internal:fleet-update:install-cli'] ?? null;

    expect($command)->toBeInstanceOf(Command::class);

    $exitCode = $command instanceof Command ? $command->run($input, $output) : 1;

    return [$exitCode, trim($output->fetch())];
}

/**
 * @return array<string, mixed>
 */
function fleet_update_install_cli_success_data(string $output): array
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

function make_fleet_update_install_cli_workspace(): string
{
    $workspace = sys_get_temp_dir().'/orbit-fleet-update-install-cli-'.bin2hex(random_bytes(8));

    mkdir("{$workspace}/artifact", recursive: true);
    mkdir("{$workspace}/bin", recursive: true);
    $artifact = "{$workspace}/artifact/orbit";

    file_put_contents($artifact, <<<'SH'
        #!/usr/bin/env sh
        echo "Orbit 9.9.9"
        SH);
    chmod(filename: $artifact, permissions: 0o755);

    return $workspace;
}
