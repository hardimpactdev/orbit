<?php

declare(strict_types=1);

namespace App\Commands\Proxy;

use App\Commands\Concerns\ResolvesHostContext;
use App\Commands\GatewayCommand;
use App\Exceptions\GatewayApiException;

final class ProxyListCommand extends GatewayCommand
{
    use ResolvesHostContext;

    #[\Override]
    protected $signature = 'proxy:list
        {--node= : Filter by serving node}
        {--filter=all : Filter routes by all, app, workspace, gateway, tool, custom, or redirect}
        {--json}';

    #[\Override]
    protected $description = 'List proxy routes tracked by gateway intent.';

    public function handle(): int
    {
        try {
            $response = $this->gatewayGet('/api/proxy-routes', $this->filledQuery([
                'node' => $this->stringOption('node'),
                'filter' => $this->stringOption('filter'),
            ]));
        } catch (GatewayApiException $exception) {
            return $this->renderFailure($exception->cliFailureCode(), $exception->getMessage());
        }

        return $this->renderSuccess($response);
    }
}
