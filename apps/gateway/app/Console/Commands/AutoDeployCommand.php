<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Actions\Instances\RunAutomaticDeploysAction;
use App\Domain\Instances\Deployment\AutomaticDeployResult;
use Illuminate\Console\Command;

final class AutoDeployCommand extends Command
{
    #[\Override]
    protected $signature = 'orbit:auto-deploy';

    #[\Override]
    protected $description = 'Deploy development defaults whose branch has a new commit.';

    /** @var list<string> */
    #[\Override]
    protected $aliases = ['orbit:deploy-development-defaults'];

    public function handle(RunAutomaticDeploysAction $action): int
    {
        $failed = false;

        foreach ($action->execute() as $outcome) {
            if ($outcome->result === AutomaticDeployResult::UpToDate) {
                continue;
            }

            $line = "Instance {$outcome->instanceId}: {$outcome->result->value}"
                .($outcome->commit !== null ? ' '.substr($outcome->commit, 0, 12) : '')
                .($outcome->operationId !== null ? " operation {$outcome->operationId}" : '')
                .($outcome->errorCode !== null ? " {$outcome->errorCode}" : '');

            if ($outcome->result->isError()) {
                $failed = true;
                $this->error($line);
            } else {
                $this->line($line);
            }
        }

        return $failed ? self::FAILURE : self::SUCCESS;
    }
}
