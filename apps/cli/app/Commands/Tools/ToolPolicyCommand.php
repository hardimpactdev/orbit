<?php

declare(strict_types=1);

namespace App\Commands\Tools;

use App\Commands\GatewayCommand;
use App\Support\Console\ConsoleWriter;
use Orbit\Sdk\Responses\Tools\ToolPolicyResponse;

abstract class ToolPolicyCommand extends GatewayCommand
{
    protected function renderPolicy(ToolPolicyResponse $policy): int
    {
        if ($this->option('json') === true || $policy->document !== null) {
            $this->writeJson($policy->toArray());

            return self::SUCCESS;
        }
        ConsoleWriter::write($this->output, $this->humanRenderer()->detail('Tool policy', [
            'Mode' => $policy->mode,
            'Catalog' => $policy->catalogPath ?? ($policy->mode === 'file' ? 'Release default' : null),
            'Snapshot ID' => $policy->snapshotId,
            'Request ID' => $policy->requestId,
        ]));

        return self::SUCCESS;
    }
}
