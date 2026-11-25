<?php

declare(strict_types=1);

namespace App\Commands\Tools;

use App\Repositories\GatewayConfigRepository;
use App\Services\GatewayConnectorFactory;
use Orbit\Sdk\Requests\Tools\SetToolPolicyRequest;
use Orbit\Sdk\Responses\Tools\ToolPolicyResponse;

final class SetToolPolicyCommand extends ToolPolicyCommand
{
    #[\Override]
    protected $signature = 'tool:mode:set {mode : File or database mode} {--catalog= : Absolute path on the Gateway to tools.yaml} {--json : Return machine-readable JSON}';

    #[\Override]
    protected $description = 'Atomically activate a Gateway Tool policy without running Node work.';

    public function handle(GatewayConfigRepository $repository, GatewayConnectorFactory $connectors): int
    {
        $connector = $this->gatewayConnector($repository, $connectors);
        if ($connector === null) {
            return self::FAILURE;
        }
        $response = $this->send($connector, new SetToolPolicyRequest((string) $this->argument('mode'), $this->stringOption('catalog')), ToolPolicyResponse::class);

        return $response instanceof ToolPolicyResponse ? $this->renderPolicy($response) : self::FAILURE;
    }
}
