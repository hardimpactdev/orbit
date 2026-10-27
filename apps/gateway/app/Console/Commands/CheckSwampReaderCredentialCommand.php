<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Actions\Tasks\CheckSwampReaderCredentialAction;
use Illuminate\Console\Command;
use Throwable;

final class CheckSwampReaderCredentialCommand extends Command
{
    #[\Override]
    protected $signature = 'gateway:swamp-reader:check';

    #[\Override]
    protected $description = 'Request Orbit assistance when the Swamp reader expires within seven days.';

    public function handle(CheckSwampReaderCredentialAction $action): int
    {
        try {
            $group = $action->execute();
            $this->info($group === null ? 'No Swamp reader rotation is due.' : 'Swamp reader rotation needs assistance on task group '.$group.'.');

            return self::SUCCESS;
        } catch (Throwable) {
            $this->error('Cannot check Swamp reader rotation. Check the private shared env, release lock, Tasks extension, and orbit Project.');

            return self::FAILURE;
        }
    }
}
