<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Actions\Tasks\CheckSwampReaderCredentialAction;
use App\Domain\GatewayReleases\GatewayReleaseException;
use App\Domain\Shared\ResourceOperationException;
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
        } catch (GatewayReleaseException $exception) {
            // A release holds the lock for its whole run. The token is due only seven days before it expires, so the next hourly check reads it again.
            if ($exception->errorCode === 'gateway.release_in_progress') {
                $this->info('A Gateway release holds the release lock; the Swamp reader is checked at the next hourly run.');

                return self::SUCCESS;
            }

            return $this->failed($exception);
        } catch (Throwable $exception) {
            return $this->failed($exception);
        }
    }

    /** Names the failure by its error code or class, never by its message, which can carry a setting. */
    private function failed(Throwable $exception): int
    {
        $this->error('Cannot check Swamp reader rotation ('.($exception instanceof ResourceOperationException ? $exception->errorCode : $exception::class).'). Check the private shared env, release lock, Tasks extension, and orbit Project.');

        return self::FAILURE;
    }
}
