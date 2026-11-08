<?php

declare(strict_types=1);

namespace App\Actions\Proof;

use App\Actions\Tasks\RequireTasksExtensionAction;
use App\Data\Proof\StartProofRunData;
use App\Domain\Proof\ProofRunIntent;
use App\Models\Node;
use App\Models\Operation;

final readonly class StartProofRunAction
{
    public function __construct(private RequireTasksExtensionAction $extension, private ProofRunIntent $intent) {}

    public function execute(StartProofRunData $input, string $triggeredBy, ?string $requestId, Node $caller): Operation
    {
        $this->extension->execute();

        return $this->intent->start($input->commit, $input->all, $triggeredBy, $requestId, $input->reportOnly, $input->keepFailed, $caller);
    }
}
