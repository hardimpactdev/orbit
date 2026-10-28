<?php

declare(strict_types=1);

namespace App\Commands\Tools;

use App\Commands\Concerns\FollowsOperations;
use App\Repositories\GatewayConfigRepository;
use App\Services\GatewayConnectorFactory;
use Orbit\Sdk\Requests\Tools\AdoptToolRequest;
use Orbit\Sdk\Responses\Operations\OperationResponse;

final class AdoptToolCommand extends ToolCommand
{
    use FollowsOperations;

    #[\Override]
    protected $signature = 'tool:adopt
        {package : Manager-native package coordinate}
        {--node= : Numeric target node ID}
        {--manager= : Tool manager name}
        {--constraint= : Optional SemVer safety constraint}
        {--yes : Skip the ownership confirmation prompt}
        {--idempotency-key= : Reuse a stable operation start key}
        {--json : Return machine-readable JSON}';

    #[\Override]
    protected $description = 'Adopt one installed package without changing it.';

    public function handle(
        GatewayConfigRepository $repository,
        GatewayConnectorFactory $connectors,
    ): int {
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

        $profile = $this->activeGatewayProfile($repository);
        if ($profile === null) {
            return self::FAILURE;
        }
        $operation = $this->send($connector, new AdoptToolRequest($nodeId, $manager, $package, $constraint, $this->stringOption('idempotency-key')), OperationResponse::class);

        return $operation instanceof OperationResponse
            ? $this->followOperation($connector, $profile, $operation, "Adopt Tool [{$package}]", 'Tool')
            : self::FAILURE;
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
