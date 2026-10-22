<?php

declare(strict_types=1);

namespace Tests\Support;

use Illuminate\Filesystem\Filesystem;
use RuntimeException;
use Symfony\Component\Process\Process;

/** Read-only, real RSA-4096 CAs per worker; each test receives independent files. */
final class RootCaFixture
{
    /** @var array<string, array{key: string, certificate: string, configuration: array{subject: string, days: int, extensions: list<string>}}> */
    private static array $materials = [];

    /**
     * Call from beforeAll, outside individual certificate issuance proofs.
     *
     * @param  list<string>  $extensions
     */
    public static function prepare(string $name = 'primary', string $subject = '/CN=Orbit Root CA', int $days = 3650, array $extensions = []): void
    {
        $configuration = ['subject' => $subject, 'days' => $days, 'extensions' => $extensions];
        if (isset(self::$materials[$name])) {
            if (self::$materials[$name]['configuration'] !== $configuration) {
                throw new RuntimeException('A named root CA fixture cannot change its configuration.');
            }

            return;
        }
        $home = sys_get_temp_dir().'/orbit-root-ca-fixture-'.bin2hex(random_bytes(8));
        mkdir($home, 0o700);

        try {
            (new Process(['openssl', 'genrsa', '-out', $home.'/root.key', '4096']))->mustRun();
            $arguments = ['openssl', 'req', '-x509', '-new', '-key', $home.'/root.key', '-out', $home.'/root.pem', '-days', (string) $days, '-subj', $subject];
            foreach ($extensions as $extension) {
                $arguments[] = '-addext';
                $arguments[] = $extension;
            }
            (new Process($arguments))->mustRun();
            $key = file_get_contents($home.'/root.key');
            $certificate = file_get_contents($home.'/root.pem');
            if (! is_string($key) || ! is_string($certificate)) {
                throw new RuntimeException('Could not read the root CA fixture.');
            }
            self::$materials[$name] = ['key' => $key, 'certificate' => $certificate, 'configuration' => $configuration];
        } finally {
            (new Filesystem)->deleteDirectory($home);
        }
    }

    public static function install(string $directory, string $name = 'primary'): void
    {
        $material = self::$materials[$name] ?? throw new RuntimeException('Prepare the root CA fixture in beforeAll.');
        file_put_contents($directory.'/root.key', $material['key']);
        chmod($directory.'/root.key', 0o600);
        file_put_contents($directory.'/root.pem', $material['certificate']);
        chmod($directory.'/root.pem', 0o644);
    }
}
