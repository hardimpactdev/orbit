<?php

declare(strict_types=1);

namespace App\Commands\Tools;

use App\Repositories\GatewayConfigRepository;
use App\Services\GatewayConnectorFactory;
use App\Support\GatewayFailureRenderer;
use Orbit\Sdk\GatewayApiException;
use Orbit\Sdk\Requests\Tools\AdoptToolRequest;
use Orbit\Sdk\Responses\Nodes\NodeAgentResponse;
use Orbit\Sdk\Responses\Tools\AdoptedToolResponse;

/** Records an installed package as a Tool from the Node agent's latest report. The answer comes at once; there is no task. */
final class AdoptToolCommand extends ToolCommand
{
    #[\Override]
    protected $signature = 'tool:adopt
        {package : Manager-native package coordinate}
        {--node= : Numeric target node ID}
        {--manager= : Tool manager name}
        {--constraint= : Optional SemVer safety constraint}
        {--repository= : Signed apt repository as a JSON object}
        {--yes : Skip the ownership confirmation prompt}
        {--json : Return machine-readable JSON}';

    #[\Override]
    protected $description = 'Adopt one installed package without changing it.';

    public function handle(
        GatewayConfigRepository $repository,
        GatewayConnectorFactory $connectors,
    ): int {
        $repositoryDefinition = $this->repositoryDefinition($this->option('repository'));
        if ($repositoryDefinition === false) {
            return self::FAILURE;
        }
        $nodeId = $this->nodeId();
        if ($nodeId === null) {
            return self::FAILURE;
        }

        $package = $this->packageName();
        if ($package === null) {
            return self::FAILURE;
        }

        $manager = $this->stringOption('manager');
        if ($manager === null) {
            $this->renderGatewayFailure('tool.manager_required', 'Tool manager is required.');

            return self::FAILURE;
        }

        $constraint = $this->stringOption('constraint');
        $connector = $this->gatewayConnector($repository, $connectors);
        if ($connector === null) {
            return self::FAILURE;
        }

        $constraintText = $constraint === null ? '' : ", constraint {$constraint}";
        if (! $this->confirmAction(
            "Take ownership of package [{$package}] ({$manager} on Node #{$nodeId}{$constraintText}) and manage later updates and removal?",
            'Tool adoption cancelled.',
        )) {
            return self::FAILURE;
        }

        try {
            $adopted = $this->sendOrThrow($connector, new AdoptToolRequest($nodeId, $manager, $package, $constraint, $repositoryDefinition), AdoptedToolResponse::class);
        } catch (GatewayApiException $exception) {
            $agent = NodeAgentResponse::tryFromGatewayData($exception->details()['agent'] ?? null);
            $this->writeAgent($nodeId, $agent);
            $code = $exception->errorCode() ?? 'gateway.request_failed';
            $outdated = $agent?->state === 'offline' ? " (possibly outdated, agent silent since {$agent->since})" : '';

            return $this->renderGatewayFailure($code, $exception->getMessage(), $exception->requestId(),
                $exception->getMessage().$outdated, GatewayFailureRenderer::safeDetails($code, $exception->details()));
        }
        if ($this->option('json') === true) {
            $this->writeJson($adopted->toArray());

            return self::SUCCESS;
        }
        // The agent comes first: while it is offline, the report the Gateway checked may be outdated.
        $this->writeAgent($nodeId, $adopted->agent);
        if ($adopted->possiblyOutdated()) {
            $this->writeHumanMessage("Possibly outdated, agent silent since {$adopted->agent?->since}.");
        }

        return $this->renderTool($adopted->tool, "Tool [{$package}] recorded as installed {$adopted->tool->installedVersion}.");
    }

    private function writeAgent(int $nodeId, ?NodeAgentResponse $agent): void
    {
        if ($agent !== null && $this->option('json') !== true) {
            $this->writeHumanMessage("Node #{$nodeId}: agent {$agent->label()}");
        }
    }

    private function packageName(): ?string
    {
        $package = $this->argument('package');

        if ($package === '') {
            $this->renderGatewayFailure('tool.package_required', 'Package is required.');

            return null;
        }

        if (strlen($package) > 255 || preg_match('/[\x00-\x1F\x7F]/', $package) === 1) {
            $this->renderGatewayFailure('tool.package_invalid', 'Package is invalid.');

            return null;
        }

        return $package;
    }
}
