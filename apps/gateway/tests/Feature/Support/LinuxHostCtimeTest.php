<?php

declare(strict_types=1);

namespace Tests\Feature\Support;

use Illuminate\Filesystem\Filesystem;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Large;
use Tests\FeatureTestCase;
use Tests\Support\LinuxHost;

require_once __DIR__.'/../../Support/LinuxHostCopyFixtures.php';

#[Group('subprocess')]
#[Large] // Kernel ctime cannot be backdated; this case must age a real directory across GNU find's ctime boundary.
final class LinuxHostCtimeTest extends FeatureTestCase
{
    public function test_removes_legacy_copies_whose_change_time_is_stale(): void
    {
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
    }
}
