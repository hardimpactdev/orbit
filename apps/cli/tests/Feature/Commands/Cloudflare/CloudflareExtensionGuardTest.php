<?php

declare(strict_types=1);

use App\Services\OrbitConfigStore;
use Illuminate\Support\Facades\Http;

describe('Cloudflare extension guard', function (): void {
    beforeEach(function (): void {
        $this->tempPath = orbit_test_config_path(prefix: 'orbit-cloudflare-guard-');
        unlink_orbit_test_file($this->tempPath);

        app()->instance(OrbitConfigStore::class, new OrbitConfigStore(overridePath: $this->tempPath));
    });

    afterEach(function (): void {
        unlink_orbit_test_file($this->tempPath);
    });

    it('returns extension disabled for direct Cloudflare invocation while locally disabled', function (): void {
        Http::fake();

        [$exitCode, $output] = runCommand($this, command: 'cf-zone:list', params: [
            '--json' => true,
        ]);

        $decoded = json_decode($output, associative: true, flags: JSON_THROW_ON_ERROR);

        Http::assertNothingSent();

        expect($exitCode)
            ->toBe(1)
            ->and($decoded['error']['code'])
            ->toBe('extension_disabled')
            ->and($decoded['error']['meta']['extension'])
            ->toBe('cloudflare')
            ->and($decoded['error']['meta']['scope'])
            ->toBe('local');
    });
});
