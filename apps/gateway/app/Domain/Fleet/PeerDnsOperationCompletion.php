<?php

declare(strict_types=1);

namespace App\Domain\Fleet;

use App\Domain\Operations\OperationCompletion;
use App\Domain\Operations\OperationOutcome;
use App\Domain\Operations\OperationStatus;
use App\Domain\Shared\ResourceOperationException;
use App\Models\Operation;
use App\Support\ValidatedData;

/** Accepts only the exact admitted policy after the generic receipt check. */
final readonly class PeerDnsOperationCompletion implements OperationCompletion
{
    public function complete(Operation $operation, OperationOutcome $outcome): void
    {
        if ($outcome->status !== OperationStatus::Succeeded) {
            return;
        }
        $input = $operation->input;
        if (! PeerDnsResult::matches(ValidatedData::string($input['server']), ValidatedData::stringList($input['domains']), $outcome->result ?? [])) {
            throw new ResourceOperationException('operation.completion_unproven', 'The receipt does not prove the admitted peer DNS policy.', 409);
        }
    }
}
