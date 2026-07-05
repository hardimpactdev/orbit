<?php

declare(strict_types=1);

namespace App\Commands\Internal;

use App\Services\Operations\LocalDoctorSelfRunner;

final class DoctorSelfCommand extends InternalExecutorCommand
{
    #[\Override]
    protected $signature = 'internal:doctor-self {--operation-token=} {--json}';

    #[\Override]
    protected $description = 'Run local Orbit doctor self-check through fixed argv';

    public function handle(LocalDoctorSelfRunner $doctor): int
    {
        if (! $this->verifyOperationToken('internal:doctor-self')) {
            return self::FAILURE;
        }

        return $this->emitInternalSuccess($doctor->run());
    }
}
