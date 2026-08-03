<?php

declare(strict_types=1);

use App\Commands\Internal\SiteCertificateInstallCommand;
use Illuminate\Support\Facades\Artisan;
use LaravelZero\Framework\Application as LaravelZeroApplication;
use Orbit\Core\Http\JsonEnvelope;
use Orbit\Core\Security\OperationTokenSigner;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;

describe('internal site certificate install command', function (): void {
    beforeEach(function (): void {
        app()->forgetInstance('App\Services\Executor\OperationTokenGuard');
        fakeGateway(fakeSuccessEnvelope([
            'allowed' => true,
        ]));
        $path = getenv('PATH');
        putenv('ORBIT_SITE_CERTIFICATE_ORIGINAL_PATH='.($path === false ? '' : $path));
    });

    afterEach(function (): void {
        $path = getenv('ORBIT_SITE_CERTIFICATE_ORIGINAL_PATH');
        putenv('PATH='.($path === false ? '' : $path));
        putenv('ORBIT_SITE_CERTIFICATE_ORIGINAL_PATH');

        $fakeBinPaths = glob(sys_get_temp_dir().'/orbit-site-certificate-bin-*');

        if ($fakeBinPaths === false) {
            return;
        }

        foreach ($fakeBinPaths as $dir) {
            delete_site_certificate_fake_bin($dir);
        }

        $fixturePaths = glob(sys_get_temp_dir().'/orbit-site-certificate-home-*');

        foreach (is_array($fixturePaths) ? $fixturePaths : [] as $dir) {
            delete_site_certificate_path($dir);
        }
    });

    it('rejects a missing operation token before installing certificate material', function (): void {
        Artisan::call('internal:site-certificate:install', [
            '--json' => true,
        ]);

        expect(Artisan::output())
            ->json()
            ->toBe(JsonEnvelope::failure(
                'missing_token',
                'Operation token is required.',
            ));
    });

    it('installs certificate material through fixed argv and stdin writes', function (): void {
        $bin = install_site_certificate_fake_bin();
        $payload = [
            'cert_path' => '/home/deploy/.config/orbit/certs/cta.example.test.crt',
            'key_path' => '/home/deploy/.config/orbit/certs/cta.example.test.key',
            'cert' => 'test-cert',
            'key' => 'test-key',
            'owner' => 'deploy',
        ];

        [$exitCode, $output] = run_site_certificate_install_command($payload);
        expect($exitCode)
            ->toBe(0)
            ->and(site_certificate_success_data($output))
            ->toMatchArray([
                'cert_path' => '/home/deploy/.config/orbit/certs/cta.example.test.crt',
                'key_path' => '/home/deploy/.config/orbit/certs/cta.example.test.key',
                'owner' => 'deploy',
                'cert_bytes' => 9,
                'key_bytes' => 8,
            ])
            ->and(file_get_contents("{$bin}/calls.log"))
            ->toContain('sudo -n install -d -m 0755 /home/deploy/.config/orbit/certs')
            ->toContain('sudo -n tee /home/deploy/.config/orbit/certs/cta.example.test.crt')
            ->toContain('sudo -n tee /home/deploy/.config/orbit/certs/cta.example.test.key')
            ->toContain('sudo -n chmod 0644 /home/deploy/.config/orbit/certs/cta.example.test.crt')
            ->toContain('sudo -n chmod 0600 /home/deploy/.config/orbit/certs/cta.example.test.key')
            ->toContain('sudo -n chown deploy:deploy /home/deploy/.config/orbit/certs/cta.example.test.crt')
            ->and(file_get_contents("{$bin}/writes.log"))
            ->toContain('/home/deploy/.config/orbit/certs/cta.example.test.crt=test-cert')
            ->toContain('/home/deploy/.config/orbit/certs/cta.example.test.key=test-key');
    });

    it('installs websocket backend certificate material into the host Orbit cert directory', function (): void {
        $bin = install_site_certificate_fake_bin();
        $payload = [
            'cert_path' => '/etc/orbit/certs/10.6.0.44.crt',
            'key_path' => '/etc/orbit/certs/10.6.0.44.key',
            'cert' => 'websocket-cert',
            'key' => 'websocket-key',
            'owner' => null,
        ];

        [$exitCode, $output] = run_site_certificate_install_command($payload);
        expect($exitCode)
            ->toBe(0)
            ->and(site_certificate_success_data($output))
            ->toMatchArray([
                'cert_path' => '/etc/orbit/certs/10.6.0.44.crt',
                'key_path' => '/etc/orbit/certs/10.6.0.44.key',
                'owner' => null,
                'cert_bytes' => 14,
                'key_bytes' => 13,
            ])
            ->and(file_get_contents("{$bin}/calls.log"))
            ->toContain('sudo -n install -d -m 0755 /etc/orbit/certs')
            ->toContain('sudo -n tee /etc/orbit/certs/10.6.0.44.crt')
            ->toContain('sudo -n tee /etc/orbit/certs/10.6.0.44.key')
            ->toContain('sudo -n chmod 0644 /etc/orbit/certs/10.6.0.44.crt')
            ->toContain('sudo -n chmod 0600 /etc/orbit/certs/10.6.0.44.key')
            ->not
            ->toContain('sudo chown')
            ->and(file_get_contents("{$bin}/writes.log"))
            ->toContain('/etc/orbit/certs/10.6.0.44.crt=websocket-cert')
            ->toContain('/etc/orbit/certs/10.6.0.44.key=websocket-key');
    });

    it('installs current-user certificate material without sudo', function (): void {
        $bin = install_site_certificate_fake_bin();
        $root = sys_get_temp_dir().'/orbit-site-certificate-home-'.bin2hex(random_bytes(8));
        $certDir = "{$root}/.config/orbit/certs";
        mkdir($certDir, recursive: true);

        $payload = [
            'cert_path' => "{$certDir}/happie-nmbp.test.crt",
            'key_path' => "{$certDir}/happie-nmbp.test.key",
            'cert' => 'user-cert',
            'key' => 'user-key',
            'owner' => site_certificate_current_user(),
        ];

        [$exitCode, $output] = run_site_certificate_install_command($payload);

        expect($exitCode)
            ->toBe(0)
            ->and(site_certificate_success_data($output))
            ->toMatchArray([
                'cert_path' => "{$certDir}/happie-nmbp.test.crt",
                'key_path' => "{$certDir}/happie-nmbp.test.key",
                'owner' => site_certificate_current_user(),
                'cert_bytes' => 9,
                'key_bytes' => 8,
            ])
            ->and(file_get_contents("{$certDir}/happie-nmbp.test.crt"))
            ->toBe('user-cert')
            ->and(file_get_contents("{$certDir}/happie-nmbp.test.key"))
            ->toBe('user-key')
            ->and(substr(sprintf('%o', fileperms("{$certDir}/happie-nmbp.test.key")), -4))
            ->toBe('0600')
            ->and(file_exists("{$bin}/calls.log"))
            ->toBeFalse();
    });

    it('uses sudo install and chown when existing cert material is non-writable for the desired current user', function (): void {
        $bin = install_site_certificate_fake_bin();
        $root = sys_get_temp_dir().'/orbit-site-certificate-home-'.bin2hex(random_bytes(8));
        $certDir = "{$root}/.config/orbit/certs";
        mkdir($certDir, recursive: true);

        $certPath = "{$certDir}/ingress.example.test.crt";
        $keyPath = "{$certDir}/ingress.example.test.key";
        file_put_contents($certPath, 'legacy-root-owned-cert');
        file_put_contents($keyPath, 'legacy-root-owned-key');
        // Simulate root-owned leftovers that orbit cannot overwrite without sudo.
        chmod($certPath, 0o444);
        chmod($keyPath, 0o444);

        $owner = site_certificate_current_user();
        $payload = [
            'cert_path' => $certPath,
            'key_path' => $keyPath,
            'cert' => 'orbit-cert',
            'key' => 'orbit-key',
            'owner' => $owner,
        ];

        [$exitCode, $output] = run_site_certificate_install_command($payload);

        expect($exitCode)
            ->toBe(0)
            ->and(site_certificate_success_data($output))
            ->toMatchArray([
                'cert_path' => $certPath,
                'key_path' => $keyPath,
                'owner' => $owner,
                'cert_bytes' => 10,
                'key_bytes' => 9,
            ])
            ->and(file_get_contents("{$bin}/calls.log"))
            ->toContain("sudo -n install -d -m 0755 {$certDir}")
            ->toContain("sudo -n tee {$certPath}")
            ->toContain("sudo -n tee {$keyPath}")
            ->toContain("sudo -n chmod 0644 {$certPath}")
            ->toContain("sudo -n chmod 0600 {$keyPath}")
            ->toContain("sudo -n chown {$owner}:{$owner} {$certPath}")
            ->toContain("sudo -n chown {$owner}:{$owner} {$keyPath}")
            ->and(file_get_contents("{$bin}/writes.log"))
            ->toContain("{$certPath}=orbit-cert")
            ->toContain("{$keyPath}=orbit-key");
    });
});

/**
 * @param  array<string, mixed>  $payload
 * @return array{int, string}
 */
function run_site_certificate_install_command(array $payload): array
{
    $command = app(SiteCertificateInstallCommand::class);
    $application = app();

    if (! $application instanceof LaravelZeroApplication) {
        throw new RuntimeException('The internal command test must run inside the Laravel Zero application.');
    }

    $command->setLaravel($application);
    $output = new BufferedOutput;
    $input = new ArrayInput([
        '--operation-token' => site_certificate_signed_operation_token(),
        '--json' => true,
    ]);
    $input->setStream(fopen(
        filename: 'data://text/plain,'.rawurlencode(json_encode($payload, JSON_THROW_ON_ERROR)),
        mode: 'r',
    ));

    return [$command->run($input, $output), $output->fetch()];
}

/**
 * @return array<string, mixed>
 */
function site_certificate_success_data(string $output): array
{
    /** @var mixed $payload */
    $payload = json_decode($output, associative: true, flags: JSON_THROW_ON_ERROR);

    if (! is_array($payload)) {
        return [];
    }

    /** @var mixed $data */
    $data = data_get(target: $payload, key: 'success.data');

    if (! is_array($data)) {
        return [];
    }

    /** @var array<string, mixed> $data */
    return $data;
}

function site_certificate_signed_operation_token(
    string $id = 'site-certificate-install',
    string $node = 'app-dev',
    string $command = 'internal:site-certificate:install',
    ?int $issuedAt = null,
    ?int $expiresAt = null,
): string {
    $issuedAt ??= time() - 10;
    $expiresAt ??= time() + 120;

    return new OperationTokenSigner()
        ->sign(
            secret: site_certificate_operation_secret(),
            id: $id,
            node: $node,
            command: $command,
            issuedAt: $issuedAt,
            expiresAt: $expiresAt,
        )
        ->toString();
}

function site_certificate_operation_secret(): string
{
    return implode('-', ['gateway', 'secret']);
}

function install_site_certificate_fake_bin(): string
{
    $dir = sys_get_temp_dir().'/orbit-site-certificate-bin-'.bin2hex(random_bytes(8));
    mkdir($dir);

    file_put_contents("{$dir}/sudo", <<<'BASH'
        #!/usr/bin/env bash
        dir="$(cd "$(dirname "$0")" && pwd)"
        printf 'sudo %s\n' "$*" >>"$dir/calls.log"

        if [ "${1:-}" = -n ]; then
            shift
        fi

        if [ "${1:-}" = tee ]; then
            path="${2:-}"
            printf '%s=' "$path" >>"$dir/writes.log"
            cat >>"$dir/writes.log"
            printf '\n' >>"$dir/writes.log"
        fi
        BASH);
    chmod(filename: "{$dir}/sudo", permissions: 0o755);

    $path = getenv('PATH');
    putenv("PATH={$dir}:".($path === false ? '' : $path));

    return $dir;
}

function delete_site_certificate_fake_bin(string $path): void
{
    foreach (['sudo', 'calls.log', 'writes.log'] as $file) {
        $filePath = "{$path}/{$file}";

        if (is_file($filePath)) {
            unlink($filePath);
        }
    }

    if (is_dir($path)) {
        rmdir($path);
    }
}

function site_certificate_current_user(): string
{
    if (function_exists('posix_geteuid') && function_exists('posix_getpwuid')) {
        $entry = posix_getpwuid(posix_geteuid());
        $user = is_array($entry) ? $entry['name'] ?? null : null;

        if (is_string($user) && $user !== '') {
            return $user;
        }
    }

    $user = getenv('USER');
    $user = $user !== false ? $user : getenv('LOGNAME');

    if (is_string($user) && $user !== '') {
        return $user;
    }

    throw new RuntimeException('Unable to resolve current test user.');
}

function delete_site_certificate_path(string $path): void
{
    if (is_file($path) || is_link($path)) {
        unlink($path);

        return;
    }

    if (! is_dir($path)) {
        return;
    }

    $entries = scandir($path);

    foreach ($entries !== false ? $entries : [] as $entry) {
        if ($entry === '.' || $entry === '..') {
            continue;
        }

        delete_site_certificate_path("{$path}/{$entry}");
    }

    rmdir($path);
}
