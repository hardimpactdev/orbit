<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Artisan;
use Orbit\Core\Http\JsonEnvelope;
use Orbit\Core\Security\OperationTokenSigner;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;

describe('internal caddy config command', function (): void {
    beforeEach(function (): void {
        app()->forgetInstance('App\Services\Executor\OperationTokenGuard');
        fakeGateway(fakeSuccessEnvelope([
            'allowed' => true,
        ]));
        $originalPath = getenv('PATH');
        $this->originalPath = $originalPath === false ? '' : $originalPath;
    });

    afterEach(function (): void {
        putenv("PATH={$this->originalPath}");

        $fakeBinPaths = glob(sys_get_temp_dir().'/orbit-caddy-config-bin-*');

        if ($fakeBinPaths === false) {
            return;
        }

        foreach ($fakeBinPaths as $dir) {
            delete_caddy_config_fake_bin($dir);
        }
    });

    it('rejects a missing operation token before reading stdin', function (): void {
        [$exitCode, $output] = run_internal_caddy_config_command([
            'action' => 'write-site',
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

    it('rejects invalid actions after token validation', function (): void {
        [$exitCode, $output] = run_internal_caddy_config_command(
            [
                'action' => 'delete',
                '--operation-token' => caddy_config_signed_operation_token(),
                '--json' => true,
            ],
            json_encode(['domain' => 'docs.test'], JSON_THROW_ON_ERROR),
        );

        $payload = json_decode($output, associative: true, flags: JSON_THROW_ON_ERROR);

        expect($exitCode)
            ->toBe(1)
            ->and($payload['error']['code'] ?? null)
            ->toBe('validation_failed')
            ->and($payload['error']['message'] ?? null)
            ->toBe('Caddy config action is invalid.');
    });

    it('writes site configs and reloads through fixed argv commands', function (): void {
        $bin = install_caddy_config_fake_bin();

        [$writeExitCode, $writeOutput] = run_internal_caddy_config_command(
            [
                'action' => 'write-site',
                '--operation-token' => caddy_config_signed_operation_token(id: 'caddy-config.write-site'),
                '--json' => true,
            ],
            json_encode([
                'domain' => 'docs.test',
                'content' => "docs.test {\n  respond ok\n}\n",
            ], JSON_THROW_ON_ERROR),
        );
        [$reloadExitCode] = run_internal_caddy_config_command(
            [
                'action' => 'reload',
                '--operation-token' => caddy_config_signed_operation_token(id: 'caddy-config.reload'),
                '--json' => true,
            ],
            json_encode(['container' => 'orbit-caddy'], JSON_THROW_ON_ERROR),
        );

        $payload = json_decode($writeOutput, associative: true, flags: JSON_THROW_ON_ERROR);

        expect($writeExitCode)
            ->toBe(0)
            ->and($reloadExitCode)
            ->toBe(0)
            ->and($payload['success']['data']['path'] ?? null)
            ->toBe('/etc/caddy/sites/docs.test.caddy')
            ->and(file_get_contents("{$bin}/calls.log"))
            ->toContain('sudo tee /etc/caddy/sites/docs.test.caddy')
            ->toContain(
                'docker exec orbit-caddy caddy reload --config /etc/caddy/Caddyfile --adapter caddyfile --address localhost:2019',
            )
            ->and(file_get_contents("{$bin}/stdin.log"))
            ->toContain("docs.test {\n  respond ok\n}");
    });
});

function caddy_config_signed_operation_token(
    string $id = 'caddy-config',
    string $node = 'app-dev',
    string $command = 'internal:caddy-config',
    ?int $issuedAt = null,
    ?int $expiresAt = null,
): string {
    $issuedAt ??= time() - 10;
    $expiresAt ??= time() + 120;

    return new OperationTokenSigner()
        ->sign(
            secret: caddy_config_operation_secret(),
            id: $id,
            node: $node,
            command: $command,
            issuedAt: $issuedAt,
            expiresAt: $expiresAt,
        )
        ->toString();
}

function caddy_config_operation_secret(): string
{
    return implode('-', ['gateway', 'secret']);
}

/**
 * @param  array<string, mixed>  $parameters
 * @return array{int, string}
 */
function run_internal_caddy_config_command(array $parameters = [], string $stdin = ''): array
{
    $stream = fopen(filename: 'php://temp', mode: 'r+');
    fwrite($stream, $stdin);
    rewind($stream);

    $input = new ArrayInput($parameters);
    $input->setStream($stream);

    $output = new BufferedOutput;
    $command = Artisan::all()['internal:caddy-config'] ?? null;

    if (! $command instanceof Symfony\Component\Console\Command\Command) {
        throw new RuntimeException('internal:caddy-config command is not registered.');
    }

    $exitCode = $command->run($input, $output);

    return [$exitCode, trim($output->fetch())];
}

function install_caddy_config_fake_bin(): string
{
    $dir = sys_get_temp_dir().'/orbit-caddy-config-bin-'.bin2hex(random_bytes(8));
    mkdir($dir);

    file_put_contents("{$dir}/sudo", <<<'PHP'
        #!/usr/bin/env php
        <?php
        file_put_contents(__DIR__.'/calls.log', 'sudo '.implode(' ', array_slice($argv, 1)).PHP_EOL, FILE_APPEND);
        $stdin = stream_get_contents(STDIN);
        if ($stdin !== '') {
            file_put_contents(__DIR__.'/stdin.log', $stdin, FILE_APPEND);
        }
        exit(0);
        PHP);
    file_put_contents("{$dir}/docker", <<<'PHP'
        #!/usr/bin/env php
        <?php
        file_put_contents(__DIR__.'/calls.log', 'docker '.implode(' ', array_slice($argv, 1)).PHP_EOL, FILE_APPEND);
        exit(0);
        PHP);
    chmod(filename: "{$dir}/sudo", permissions: 0o755);
    chmod(filename: "{$dir}/docker", permissions: 0o755);

    $path = getenv('PATH');
    putenv("PATH={$dir}:".($path === false ? '' : $path));

    return $dir;
}

function delete_caddy_config_fake_bin(string $path): void
{
    delete_caddy_config_file("{$path}/sudo");
    delete_caddy_config_file("{$path}/docker");
    delete_caddy_config_file("{$path}/calls.log");
    delete_caddy_config_file("{$path}/stdin.log");

    if (is_dir($path)) {
        rmdir($path);
    }
}

function delete_caddy_config_file(string $path): void
{
    if (! is_file($path)) {
        return;
    }

    unlink($path);
}
