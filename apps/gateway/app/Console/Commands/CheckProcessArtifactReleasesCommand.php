<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Actions\Processes\CheckLatestProcessReleasesAction;
use Illuminate\Console\Command;

final class CheckProcessArtifactReleasesCommand extends Command
{
    #[\Override]
    protected $signature = 'process:artifacts:check-latest';

    #[\Override]
    protected $description = 'Store the latest upstream release of each Process artifact repository.';

    #[\Override]
    protected $hidden = true;

    public function handle(CheckLatestProcessReleasesAction $action): int
    {
        $result = $action->execute();
        $this->line("Checked {$result['checked']} artifact repositories; {$result['failed']} failed and keep their last answer.");

        return self::SUCCESS;
    }
}
