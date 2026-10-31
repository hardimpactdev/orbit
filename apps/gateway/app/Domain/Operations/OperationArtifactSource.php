<?php

declare(strict_types=1);

namespace App\Domain\Operations;

use App\Models\Operation;

/** Existing target-owned files, exposed only through a declared private-material field. */
interface OperationArtifactSource extends OperationSecretSource
{
    public function artifact(Operation $operation, int $ownerId): OperationArtifact;
}
