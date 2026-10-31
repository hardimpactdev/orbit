<?php

declare(strict_types=1);

namespace App\Commands\Analytics;

use App\Commands\Concerns\FollowsOperations;
use App\Commands\GatewayCommand;
use App\Repositories\GatewayConfigRepository;
use App\Services\GatewayConnectorFactory;
use Laravel\Prompts\TextPrompt;
use Orbit\Sdk\Requests\Analytics\UpdateAnalyticsRequest;
use Orbit\Sdk\Responses\Operations\OperationResponse;

final class UpdateAnalyticsCommand extends GatewayCommand
{
    use FollowsOperations;

    #[\Override]
    protected $signature = 'analytics:update
        {version? : Plausible Community Edition version, for example 3.2.1}
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

        $response = $this->sendWithProgress(
            $connector,
            new UpdateAnalyticsRequest($version),
            OperationResponse::class,
            ['Update Plausible', 'Requesting the update', 'Update accepted'],
        );

        if (! $response instanceof OperationResponse) {
            return self::FAILURE;
        }

        $profile = $this->activeGatewayProfile($repository);

        return $profile === null ? self::FAILURE : $this->followOperation($connector, $profile, $response, 'Update Plausible', 'Plausible');
    }

    private static function versionError(string $version): ?string
    {
        return preg_match('/\A\d+\.\d+\.\d+\z/D', $version) === 1 ? null : 'Use three numbers, for example 3.2.1.';
    }
}
