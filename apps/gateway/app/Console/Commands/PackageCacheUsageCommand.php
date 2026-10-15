<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Actions\TaskVms\ReadPackageCacheUsageAction;
use Illuminate\Console\Command;

final class PackageCacheUsageCommand extends Command
{
    use ReadsStringInput;

    #[\Override]
    protected $signature = 'task-vms:package-cache-usage {package} {version}';

    #[\Override]
    protected $description = 'List default-branch Project usage and verified host hashes for a cached package.';

    public function handle(): int
    {
        $package = $this->stringArgument('package');
        $version = $this->stringArgument('version');
        $this->line(json_encode(app(ReadPackageCacheUsageAction::class)->execute($package, $version), JSON_THROW_ON_ERROR));

        return self::SUCCESS;
    }
}
