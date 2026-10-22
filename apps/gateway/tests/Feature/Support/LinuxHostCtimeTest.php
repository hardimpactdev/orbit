<?php

declare(strict_types=1);

use Illuminate\Filesystem\Filesystem;
use Tests\Support\LinuxHost;

require_once __DIR__.'/../../Support/LinuxHostCopyFixtures.php';

pest()->group('subprocess');

it('removes legacy copies whose change time is stale', function (): void {
    // Change time cannot be backdated with touch; prove the real GNU find boundary.
    if (LinuxHost::delegate($this)) {
        return;
    }
    $directory = linux_host_temporary_directory();
    $legacy = "{$directory}/legacy";
    try {
        mkdir("{$legacy}/abandoned", 0o755, true);
        sleep(2);
        $prepare = linux_host_run(['sh', '-c', LinuxHost::prepareScript(escapeshellarg("{$directory}/base"), 'fresh', $legacy, 0)]);
        expect($prepare->getExitCode())->toBe(0, $prepare->getErrorOutput());
        $this->assertDirectoryDoesNotExist("{$legacy}/abandoned");
        $this->assertDirectoryDoesNotExist($legacy);
        $this->assertFileExists("{$directory}/base/fresh/alive");
    } finally {
        new Filesystem()->deleteDirectory($directory);
    }
});
