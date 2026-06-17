<?php

declare(strict_types=1);

use App\Services\Version\InstallMetadataStore;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;

describe('version', function (): void {
    beforeEach(function (): void {
        $this->previousTimezone = date_default_timezone_get();
        $this->previousDisplayTimezone = getenv('ORBIT_DISPLAY_TIMEZONE');
        $this->previousBinPath = getenv('ORBIT_BIN_PATH');
        $this->previousHome = getenv('HOME');
        $this->versionTempRoot = sys_get_temp_dir().'/orbit-version-test-'.getmypid();
        $this->installMetadataPath = $this->versionTempRoot.'/install.json';
        File::deleteDirectory($this->versionTempRoot);
        File::ensureDirectoryExists($this->versionTempRoot);
        date_default_timezone_set('Europe/Amsterdam');
        putenv('ORBIT_DISPLAY_TIMEZONE=Europe/Amsterdam');
        config()->set('app.version', '0.1.105');
        putenv("ORBIT_INSTALL_METADATA_PATH={$this->installMetadataPath}");
        $this->previousArgvPath = $_SERVER['argv'][0] ?? null;
    });

    afterEach(function (): void {
        date_default_timezone_set($this->previousTimezone);
        $this->previousDisplayTimezone === false ? putenv('ORBIT_DISPLAY_TIMEZONE') : putenv("ORBIT_DISPLAY_TIMEZONE={$this->previousDisplayTimezone}");
        $this->previousBinPath === false ? putenv('ORBIT_BIN_PATH') : putenv("ORBIT_BIN_PATH={$this->previousBinPath}");
        $this->previousHome === false ? putenv('HOME') : putenv("HOME={$this->previousHome}");
        putenv('ORBIT_INSTALL_METADATA_PATH');
        if ($this->previousArgvPath === null) {
            unset($_SERVER['argv'][0]);
        } else {
            $_SERVER['argv'][0] = $this->previousArgvPath;
        }
        File::deleteDirectory($this->versionTempRoot);
    });

    it('renders installed and release metadata for humans', function (): void {
        file_put_contents($this->installMetadataPath, json_encode([
            'schema_version' => 1,
            'version' => '0.1.105',
            'installed_at' => '2026-06-17T10:54:00+00:00',
        ], JSON_THROW_ON_ERROR));

        Http::fake([
            'https://github.com/hardimpactdev/orbit/releases/latest/download/orbit-release-manifest.json' => Http::response(releaseManifest('0.1.105', '2026-06-17T10:47:00Z')),
        ]);

        [$exitCode, $output] = runCommand($this, 'version');

        expect($exitCode)->toBe(0)
            ->and($output)->toContain('Version       0.1.105')
            ->and($output)->toContain('Released at   17-06-2026 - 12:47')
            ->and($output)->toContain('Installed at  17-06-2026 - 12:54')
            ->and($output)->not->toContain('new version available');
    });

    it('annotates the human version line when a newer release exists', function (): void {
        file_put_contents($this->installMetadataPath, json_encode([
            'schema_version' => 1,
            'version' => '0.1.105',
            'installed_at' => '2026-06-17T10:54:00+00:00',
        ], JSON_THROW_ON_ERROR));

        Http::fake([
            'https://github.com/hardimpactdev/orbit/releases/latest/download/orbit-release-manifest.json' => Http::response(releaseManifest('0.1.108', '2026-06-17T11:04:00Z')),
            'https://github.com/hardimpactdev/orbit/releases/download/v0.1.105/orbit-release-manifest.json' => Http::response(releaseManifest('0.1.105', '2026-06-17T10:47:00Z')),
        ]);

        [$exitCode, $output] = runCommand($this, 'version');

        expect($exitCode)->toBe(0)
            ->and($output)->toContain('Version       0.1.105 (new version available: 0.1.108)')
            ->and($output)->toContain('Released at   17-06-2026 - 12:47')
            ->and($output)->toContain('Installed at  17-06-2026 - 12:54');
    });

    it('returns the same metadata in the JSON envelope', function (): void {
        file_put_contents($this->installMetadataPath, json_encode([
            'schema_version' => 1,
            'version' => '0.1.105',
            'installed_at' => '2026-06-17T10:54:00+00:00',
        ], JSON_THROW_ON_ERROR));

        Http::fake([
            'https://github.com/hardimpactdev/orbit/releases/latest/download/orbit-release-manifest.json' => Http::response(releaseManifest('0.1.108', '2026-06-17T11:04:00Z')),
            'https://github.com/hardimpactdev/orbit/releases/download/v0.1.105/orbit-release-manifest.json' => Http::response(releaseManifest('0.1.105', '2026-06-17T10:47:00Z')),
        ]);

        [$exitCode, $output] = runCommand($this, 'version', ['--json' => true]);

        $decoded = json_decode($output, associative: true, flags: JSON_THROW_ON_ERROR);

        expect($exitCode)->toBe(0)
            ->and($decoded['success']['data'])->toBe([
                'version' => '0.1.105',
                'latest_version' => '0.1.108',
                'update_available' => true,
                'released_at' => '2026-06-17T10:47:00+00:00',
                'installed_at' => '2026-06-17T10:54:00+00:00',
            ]);
    });

    it('does not fail when release lookups are unavailable', function (): void {
        file_put_contents($this->installMetadataPath, json_encode([
            'schema_version' => 1,
            'version' => '0.1.105',
            'installed_at' => '2026-06-17T10:54:00+00:00',
        ], JSON_THROW_ON_ERROR));

        Http::fake([
            'https://github.com/hardimpactdev/orbit/releases/latest/download/orbit-release-manifest.json' => Http::response([], 503),
            'https://github.com/hardimpactdev/orbit/releases/download/v0.1.105/orbit-release-manifest.json' => Http::response([], 503),
            'https://api.github.com/repos/hardimpactdev/orbit/releases/latest' => Http::response([], 503),
            'https://api.github.com/repos/hardimpactdev/orbit/releases/tags/v0.1.105' => Http::response([], 503),
        ]);

        [$exitCode, $output] = runCommand($this, 'version', ['--json' => true]);

        $decoded = json_decode($output, associative: true, flags: JSON_THROW_ON_ERROR);

        expect($exitCode)->toBe(0)
            ->and($decoded['success']['data'])->toBe([
                'version' => '0.1.105',
                'latest_version' => null,
                'update_available' => false,
                'released_at' => null,
                'installed_at' => '2026-06-17T10:54:00+00:00',
            ]);
    });

    it('falls back to the GitHub releases API when public manifests are unavailable', function (): void {
        file_put_contents($this->installMetadataPath, json_encode([
            'schema_version' => 1,
            'version' => '0.1.105',
            'installed_at' => '2026-06-17T10:54:00+00:00',
        ], JSON_THROW_ON_ERROR));

        Http::fake([
            'https://github.com/hardimpactdev/orbit/releases/latest/download/orbit-release-manifest.json' => Http::response([], 404),
            'https://api.github.com/repos/hardimpactdev/orbit/releases/latest' => Http::response([
                'tag_name' => 'v0.1.105',
                'published_at' => '2026-06-17T10:47:00Z',
            ]),
        ]);

        [$exitCode, $output] = runCommand($this, 'version', ['--json' => true]);

        $decoded = json_decode($output, associative: true, flags: JSON_THROW_ON_ERROR);

        expect($exitCode)->toBe(0)
            ->and($decoded['success']['data']['latest_version'])->toBe('0.1.105')
            ->and($decoded['success']['data']['released_at'])->toBe('2026-06-17T10:47:00+00:00');
    });

    it('falls back to the user-local binary mtime when install metadata is missing', function (): void {
        $home = $this->versionTempRoot.'/home';
        $binaryPath = $home.'/.local/bin/orbit';
        $installedAt = CarbonImmutable::parse('2026-06-17T10:54:00+00:00');

        @mkdir(dirname($binaryPath), 0755, recursive: true);
        file_put_contents($binaryPath, '');
        touch($binaryPath, $installedAt->timestamp);

        putenv('ORBIT_BIN_PATH');
        putenv("HOME={$home}");
        $_SERVER['argv'][0] = $this->versionTempRoot.'/missing-orbit';

        Http::fake([
            'https://github.com/hardimpactdev/orbit/releases/latest/download/orbit-release-manifest.json' => Http::response(releaseManifest('0.1.105', '2026-06-17T10:47:00Z')),
        ]);

        try {
            [$exitCode, $output] = runCommand($this, 'version', ['--json' => true]);

            $decoded = json_decode($output, associative: true, flags: JSON_THROW_ON_ERROR);

            expect($exitCode)->toBe(0)
                ->and($decoded['success']['data']['installed_at'])->toBe(CarbonImmutable::createFromTimestamp($installedAt->timestamp)->toIso8601String());
        } finally {
            @unlink($binaryPath);
            @rmdir(dirname($binaryPath));
            @rmdir(dirname(dirname($binaryPath)));
            @rmdir($home);
        }
    });

    it('prefers the invoked launcher mtime over a stale user-local binary fallback', function (): void {
        $home = $this->versionTempRoot.'/home';
        $staleUserLocalBinary = $home.'/.local/bin/orbit';
        $invokedLauncher = $this->versionTempRoot.'/usr-local-bin-orbit';
        $staleInstalledAt = CarbonImmutable::parse('2026-02-15T21:30:00+00:00');
        $actualInstalledAt = CarbonImmutable::parse('2026-06-17T13:19:00+00:00');

        @mkdir(dirname($staleUserLocalBinary), 0755, recursive: true);
        file_put_contents($staleUserLocalBinary, '');
        touch($staleUserLocalBinary, $staleInstalledAt->timestamp);

        file_put_contents($invokedLauncher, '');
        touch($invokedLauncher, $actualInstalledAt->timestamp);

        putenv('ORBIT_BIN_PATH');
        putenv("HOME={$home}");
        $_SERVER['argv'][0] = $invokedLauncher;

        try {
            $installedAt = (new InstallMetadataStore)->installedAtFor('0.1.105');

            expect($installedAt)->toBe($actualInstalledAt->toIso8601String());
        } finally {
            @unlink($staleUserLocalBinary);
            @unlink($invokedLauncher);
            @rmdir(dirname($staleUserLocalBinary));
            @rmdir(dirname(dirname($staleUserLocalBinary)));
            @rmdir($home);
        }
    });

    it('resolves a bare invoked launcher name through PATH before user-local fallback', function (): void {
        $home = $this->versionTempRoot.'/home';
        $pathDirectory = $this->versionTempRoot.'/usr-local-bin';
        $staleUserLocalBinary = $home.'/.local/bin/orbit';
        $invokedLauncher = $pathDirectory.'/orbit';
        $staleInstalledAt = CarbonImmutable::parse('2026-02-15T21:30:00+00:00');
        $actualInstalledAt = CarbonImmutable::parse('2026-06-17T13:32:00+00:00');
        $previousPath = getenv('PATH');
        $previousCwd = getcwd();

        @mkdir(dirname($staleUserLocalBinary), 0755, recursive: true);
        file_put_contents($staleUserLocalBinary, '');
        touch($staleUserLocalBinary, $staleInstalledAt->timestamp);

        @mkdir($pathDirectory, 0755, recursive: true);
        file_put_contents($invokedLauncher, '');
        touch($invokedLauncher, $actualInstalledAt->timestamp);

        putenv('ORBIT_BIN_PATH');
        putenv("HOME={$home}");
        putenv("PATH={$pathDirectory}");
        $_SERVER['argv'][0] = 'orbit';
        chdir($this->versionTempRoot);

        try {
            $installedAt = (new InstallMetadataStore)->installedAtFor('0.1.105');

            expect($installedAt)->toBe($actualInstalledAt->toIso8601String());
        } finally {
            if (is_string($previousCwd)) {
                chdir($previousCwd);
            }

            $previousPath === false ? putenv('PATH') : putenv("PATH={$previousPath}");
            @unlink($staleUserLocalBinary);
            @unlink($invokedLauncher);
            @rmdir($pathDirectory);
            @rmdir(dirname($staleUserLocalBinary));
            @rmdir(dirname(dirname($staleUserLocalBinary)));
            @rmdir($home);
        }
    });
});

function releaseManifest(string $version, string $releasedAt): array
{
    return [
        'schema_version' => 1,
        'version' => $version,
        'released_at' => $releasedAt,
        'source' => 'github-release',
        'images' => [
            'gateway' => "ghcr.io/hardimpactdev/orbit-gateway:{$version}@sha256:".str_repeat('a', 64),
        ],
        'cli_artifacts' => [
            'linux-amd64' => [
                'url' => "https://github.com/hardimpactdev/orbit/releases/download/v{$version}/orbit-linux-x64",
                'sha256' => str_repeat('b', 64),
            ],
        ],
        'role_images' => [
            'orbit-caddy' => 'caddy:2-alpine',
        ],
    ];
}
