<?php

declare(strict_types=1);

namespace App\Commands\Analytics;

use App\Commands\GatewayCommand;
use App\Commands\Integrations\FollowsIntegrationOperations;
use App\Repositories\GatewayConfigRepository;
use App\Services\GatewayConnectorFactory;
use Laravel\Prompts\TextPrompt;
use Orbit\Sdk\Requests\Analytics\UpdateAnalyticsRequest;

final class UpdateAnalyticsCommand extends GatewayCommand
{
    use FollowsIntegrationOperations;

    #[\Override]
    protected $signature = 'analytics:update
        {version? : Plausible Community Edition version, for example 3.2.1}
        {--idempotency-key= : Reuse a key to retry this operation start}
        {--json : Return machine-readable JSON}';

    #[\Override]
    protected $description = 'Pin another Plausible version for the analytics role.';

    public function handle(GatewayConfigRepository $repository, GatewayConnectorFactory $factory): int
    {
        $version = $this->argument('version');

        if ($version === null && $this->consoleMode()->mayPrompt) {
            $version = $this->commandPrompts()->run(fn (): TextPrompt => new TextPrompt(
                'Plausible version',
                placeholder: '3.2.1',
                required: true,
                validate: self::versionError(...),
            ));
        }

        if (! is_string($version) || self::versionError($version) !== null) {
            return $this->renderGatewayFailure(
                'analytics.version_invalid',
                'A Plausible version has the form 3.2.1.',
                details: ['field' => 'version'],
            );
        }

        $connector = $this->gatewayConnector($repository, $factory);

        if ($connector === null) {
            return self::FAILURE;
        }

        return $this->integrationOperation($connector, $repository, new UpdateAnalyticsRequest($version, is_string($this->option('idempotency-key')) ? $this->option('idempotency-key') : null), 'Update Plausible');
    }

    private static function versionError(string $version): ?string
    {
        return preg_match('/\A\d+\.\d+\.\d+\z/D', $version) === 1 ? null : 'Use three numbers, for example 3.2.1.';
    }
}
