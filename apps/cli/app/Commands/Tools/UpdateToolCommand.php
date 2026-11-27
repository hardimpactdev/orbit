<?php

declare(strict_types=1);

namespace App\Commands\Tools;

use Orbit\Sdk\GatewayRequest;
use Orbit\Sdk\Requests\Tools\UpdateToolRequest;

final class UpdateToolCommand extends ToolActionCommand
{
    #[\Override]
    protected $signature = 'tool:update
        {tool : Numeric tool ID}
        {--tool-version= : Exact manager-native version (database mode)}
        {--repository= : Name of the Node\'s ready apt repository to install from}
        {--idempotency-key= : Reuse a stable operation start key}
        {--json : Return machine-readable JSON}';

    #[\Override]
    protected $description = 'Update one tool.';

    #[\Override]
    protected function request(int $toolId): GatewayRequest
    {
        return new UpdateToolRequest($toolId, $this->stringOption('idempotency-key'), $this->stringOption('repository'), $this->stringOption('tool-version'));
    }
}
