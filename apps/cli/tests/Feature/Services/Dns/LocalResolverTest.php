<?php

declare(strict_types=1);

use App\Services\Dns\LocalResolver;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;

describe(LocalResolver::class, function (): void {
    beforeEach(function (): void {
        $this->originalHome = getenv('HOME');
        $this->tempHome = sys_get_temp_dir().'/orbit-resolver-test-'.bin2hex(random_bytes(4));
        mkdir($this->tempHome, 0o700, true);
        putenv("HOME={$this->tempHome}");
    });

    afterEach(function (): void {
        putenv('HOME='.($this->originalHome === false ? '' : $this->originalHome));
        if (is_dir($this->tempHome)) {
            File::deleteDirectory($this->tempHome);
        }
    });

    it('resolves configDir under the host-writable Orbit config home', function (): void {
        $resolver = new LocalResolver;

        expect($resolver->configDir())->toBe("{$this->tempHome}/.config/orbit/dnsmasq.d");
    });

    it('configDir does not use storage_path', function (): void {
        $resolver = new LocalResolver;

        expect($resolver->configDir())->not->toContain(storage_path());
    });

    it('throws when HOME is not set', function (): void {
        putenv('HOME');

        $resolver = new LocalResolver;

        expect(fn () => $resolver->configDir())->toThrow(RuntimeException::class, 'HOME environment variable is not set.');
    });

    it('resolve writes the dnsmasq conf under the host-writable Orbit config home', function (): void {
        Process::fake([
            'sudo mkdir*' => Process::result('', '', 0),
            'brew services*' => Process::result('', '', 0),
        ]);

        $resolver = new LocalResolver;
        $resolver->setPlatform('macos');

        $resolver->resolve('test', '10.6.0.1');

        $expectedConf = "{$this->tempHome}/.config/orbit/dnsmasq.d/test.conf";

        expect(File::exists($expectedConf))->toBeTrue()
            ->and(File::get($expectedConf))->toBe("address=/test/10.6.0.1\n");
    });
});
