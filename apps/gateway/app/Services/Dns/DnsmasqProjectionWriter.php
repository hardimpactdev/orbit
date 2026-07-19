<?php

declare(strict_types=1);

namespace App\Services\Dns;

use Closure;
use Illuminate\Support\Facades\File;
use RuntimeException;
use Throwable;

class DnsmasqProjectionWriter
{
    private readonly DnsmasqProjectionRollback $rollback;

    public function __construct(
        private readonly string $rootPath,
        private readonly DnsmasqRuntimeManager $runtimeManager,
        ?DnsmasqProjectionRollback $rollback = null,
    ) {
        $this->rollback = $rollback ?? new DnsmasqProjectionRollback;
    }

    /** @param Closure(): array<string, string> $projections */
    public function publishAndActivate(Closure $projections): bool
    {
        return $this->materialize($projections, $this->runtimeManager->activate(...));
    }

    /** @param Closure(): array<string, string> $projections */
    public function stage(Closure $projections): bool
    {
        return $this->materialize($projections, static function (): void {});
    }

    /**
     * @param  Closure(): array<string, string>  $projections
     * @param  Closure(): void  $afterPublish
     */
    private function materialize(Closure $projections, Closure $afterPublish): bool
    {
        File::ensureDirectoryExists($this->rootPath, 0o700);
        File::ensureDirectoryExists($this->rootPath.'/dnsmasq.d', 0o700);

        $lockPath = $this->rootPath.'/.dnsmasq-projections.lock';
        $lock = fopen(filename: $lockPath, mode: 'c+');

        if ($lock === false) {
            throw new RuntimeException("Could not open DNS projection lock [{$lockPath}].");
        }

        $workspace = new DnsmasqProjectionWorkspace($this->rootPath);
        $published = [];

        try {
            if (! flock($lock, LOCK_EX)) {
                throw new RuntimeException("Could not acquire DNS projection lock [{$lockPath}].");
            }

            $changed = $workspace->stage($projections());

            if ($changed === []) {
                return false;
            }

            foreach ($changed as $target => $temporary) {
                if (! rename($temporary, $target)) {
                    $this->rollback->restore($published, $workspace->originals());

                    throw new RuntimeException("Could not publish DNS projection [{$target}].");
                }

                $published[] = $target;
                $workspace->markPublished($temporary);
            }

            try {
                $afterPublish();
            } catch (Throwable $throwable) {
                $this->rollback->restore($published, $workspace->originals());

                throw $throwable;
            }

            return true;
        } finally {
            $workspace->cleanup();
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }
}
