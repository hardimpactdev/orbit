<?php

declare(strict_types=1);

namespace Orbit\Extensions\Conn\Console;

use App\Domain\Shared\ResourceOperationException;
use Illuminate\Console\Command;
use Orbit\Extensions\Conn\Actions\Managed\EnrolConnManagedTargetAction;

/** Enrols one lab managed target or replaces its generation ([Enrol a lab target](/reference/conn#enrol-a-lab-target)). Never prints the credential. */
final class EnrolConnManagedTargetCommand extends Command
{
    /** @var string */
    #[\Override]
    protected $signature = 'conn:managed-target:enrol {environment_id} {--credential-file= : Path of the mode-0600 file that receives the service credential}';

    /** @var string */
    #[\Override]
    protected $description = 'Enrol a registered Conn environment as a lab managed target, or start its next generation';

    public function handle(EnrolConnManagedTargetAction $action): int
    {
        $file = $this->option('credential-file');
        if (! is_string($file) || $file === '') {
            $this->error('--credential-file is required.');

            return self::INVALID;
        }

        try {
            $result = $action->execute((string) $this->argument('environment_id'), $file);
        } catch (ResourceOperationException $exception) {
            $this->error($exception->errorCode.': '.$exception->getMessage());

            return self::FAILURE;
        }

        $this->info(sprintf('Target %d, environment %s, Node %d: generation %d at revision %d. Credential written to %s.',
            $result['target_id'], $result['environment_id'], $result['node_id'], $result['generation'], $result['revision'], $file));

        return self::SUCCESS;
    }
}
