<?php

declare(strict_types=1);

namespace App\Commands\Conn;

use App\Commands\GatewayCommand;
use App\Repositories\GatewayConfigRepository;
use App\Services\GatewayConnectorFactory;
use App\Support\Console\ConsoleWriter;
use App\Support\GatedExtensionCommand;
use Orbit\Sdk\Requests\Conn\ShowConnPushCredentialsRequest;
use Orbit\Sdk\Responses\Conn\ConnPushCredentialsResponse;

final class ShowConnPushCredentialsCommand extends GatewayCommand implements GatedExtensionCommand
{
    #[\Override]
    protected $signature = 'conn:push-credentials:show
        {--json : Return machine-readable JSON}';

    #[\Override]
    protected $description = 'Show whether Apple APNs push credentials are stored on the Gateway';

    public function extensionSlug(): string
    {
        return 'conn';
    }

    public function handle(GatewayConfigRepository $repository, GatewayConnectorFactory $factory): int
    {
        $connector = $this->gatewayConnector($repository, $factory);
        if ($connector === null) {
            return self::FAILURE;
        }

        $response = $this->sendWithProgress(
            $connector,
            new ShowConnPushCredentialsRequest,
            ConnPushCredentialsResponse::class,
            ['Show Conn push credentials', 'Loading APNs credential status', 'Loaded APNs credential status'],
        );

        if (! $response instanceof ConnPushCredentialsResponse) {
            return self::FAILURE;
        }

        if ($this->option('json') === true) {
            $this->writeJson($response->toArray());

            return self::SUCCESS;
        }

        ConsoleWriter::write($this->output, $this->humanRenderer()->detail(
            $response->configured
                ? 'Conn APNs push credentials are stored.'
                : 'No Conn APNs push credentials are stored.',
            [
                'Configured' => $response->configured ? 'yes' : 'no',
                'Key ID' => $response->keyId ?? 'none',
                'Team ID' => $response->teamId ?? 'none',
                'Request ID' => $response->requestId,
            ],
        ));

        return self::SUCCESS;
    }
}
