<?php

declare(strict_types=1);

namespace App\Commands\Tools;

use App\Repositories\GatewayConfigRepository;
use App\Services\GatewayConnectorFactory;
use Orbit\Sdk\Requests\Tools\ShowToolPolicyRequest;
use Orbit\Sdk\Responses\Tools\ToolPolicyResponse;

final class ShowToolPolicyCommand extends ToolPolicyCommand
{
    #[\Override]
    protected $signature = 'tool:mode {--export= : Export a snapshot or a file catalog} {--json : Return machine-readable JSON}';

    #[\Override]
    protected $description = 'Show or export the Gateway Tool policy.';

    public function handle(GatewayConfigRepository $repository, GatewayConnectorFactory $connectors): int
    {
        $connector = $this->gatewayConnector($repository, $connectors);
        if ($connector === null) {
            return self::FAILURE;
        }
        $response = $this->send($connector, new ShowToolPolicyRequest($this->stringOption('export')), ToolPolicyResponse::class);

        return $response instanceof ToolPolicyResponse ? $this->renderPolicy($response) : self::FAILURE;
    }
}
