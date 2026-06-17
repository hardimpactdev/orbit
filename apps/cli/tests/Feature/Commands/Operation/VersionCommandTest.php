<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Http;

describe('version', function (): void {
    beforeEach(function (): void {
        $this->previousTimezone = date_default_timezone_get();
        $this->previousDisplayTimezone = getenv('ORBIT_DISPLAY_TIMEZONE');
        date_default_timezone_set('Europe/Amsterdam');
        putenv('ORBIT_DISPLAY_TIMEZONE=Europe/Amsterdam');
        config()->set('app.version', '0.1.105');
        putenv('ORBIT_INSTALL_METADATA_PATH='.base_path('tests/.tmp-version-install.json'));
        @unlink(base_path('tests/.tmp-version-install.json'));
    });

    afterEach(function (): void {
        date_default_timezone_set($this->previousTimezone);
        $this->previousDisplayTimezone === false ? putenv('ORBIT_DISPLAY_TIMEZONE') : putenv("ORBIT_DISPLAY_TIMEZONE={$this->previousDisplayTimezone}");
        putenv('ORBIT_INSTALL_METADATA_PATH');
        @unlink(base_path('tests/.tmp-version-install.json'));
    });

    it('renders installed and release metadata for humans', function (): void {
        file_put_contents(base_path('tests/.tmp-version-install.json'), json_encode([
            'schema_version' => 1,
            'version' => '0.1.105',
            'installed_at' => '2026-06-17T10:54:00+00:00',
        ], JSON_THROW_ON_ERROR));

        Http::fake([
            'https://api.github.com/repos/hardimpactdev/orbit/releases/latest' => Http::response([
                'tag_name' => 'v0.1.105',
                'published_at' => '2026-06-17T10:47:00Z',
            ]),
        ]);

        [$exitCode, $output] = runCommand($this, 'version');

        expect($exitCode)->toBe(0)
            ->and($output)->toContain('Version       0.1.105')
            ->and($output)->toContain('Released at   17-06-2026 - 12:47')
            ->and($output)->toContain('Installed at  17-06-2026 - 12:54')
            ->and($output)->not->toContain('new version available');
    });

    it('annotates the human version line when a newer release exists', function (): void {
        file_put_contents(base_path('tests/.tmp-version-install.json'), json_encode([
            'schema_version' => 1,
            'version' => '0.1.105',
            'installed_at' => '2026-06-17T10:54:00+00:00',
        ], JSON_THROW_ON_ERROR));

        Http::fake([
            'https://api.github.com/repos/hardimpactdev/orbit/releases/latest' => Http::response([
                'tag_name' => 'v0.1.108',
                'published_at' => '2026-06-17T11:04:00Z',
            ]),
            'https://api.github.com/repos/hardimpactdev/orbit/releases/tags/v0.1.105' => Http::response([
                'tag_name' => 'v0.1.105',
                'published_at' => '2026-06-17T10:47:00Z',
            ]),
        ]);

        [$exitCode, $output] = runCommand($this, 'version');

        expect($exitCode)->toBe(0)
            ->and($output)->toContain('Version       0.1.105 (new version available: 0.1.108)')
            ->and($output)->toContain('Released at   17-06-2026 - 12:47')
            ->and($output)->toContain('Installed at  17-06-2026 - 12:54');
    });

    it('returns the same metadata in the JSON envelope', function (): void {
        file_put_contents(base_path('tests/.tmp-version-install.json'), json_encode([
            'schema_version' => 1,
            'version' => '0.1.105',
            'installed_at' => '2026-06-17T10:54:00+00:00',
        ], JSON_THROW_ON_ERROR));

        Http::fake([
            'https://api.github.com/repos/hardimpactdev/orbit/releases/latest' => Http::response([
                'tag_name' => 'v0.1.108',
                'published_at' => '2026-06-17T11:04:00Z',
            ]),
            'https://api.github.com/repos/hardimpactdev/orbit/releases/tags/v0.1.105' => Http::response([
                'tag_name' => 'v0.1.105',
                'published_at' => '2026-06-17T10:47:00Z',
            ]),
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
        file_put_contents(base_path('tests/.tmp-version-install.json'), json_encode([
            'schema_version' => 1,
            'version' => '0.1.105',
            'installed_at' => '2026-06-17T10:54:00+00:00',
        ], JSON_THROW_ON_ERROR));

        Http::fake([
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
});
