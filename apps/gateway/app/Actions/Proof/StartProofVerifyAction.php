<?php

declare(strict_types=1);

namespace App\Actions\Proof;

use App\Actions\Tasks\RequireTasksExtensionAction;
use App\Data\Proof\StartProofVerifyData;
use App\Domain\Proof\ProofVerifyIntent;
use App\Models\Node;
use App\Models\Operation;

final readonly class StartProofVerifyAction
{
    public function __construct(private RequireTasksExtensionAction $extension, private ProofVerifyIntent $intent) {}

    public function execute(Node $caller, StartProofVerifyData $input, string $triggeredBy, ?string $requestId): Operation
    {
        $this->extension->execute();

        return $this->intent->start($caller, $input->command, $input->group, $triggeredBy, $requestId);
    }
}
