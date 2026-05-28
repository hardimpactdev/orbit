<?php

declare(strict_types=1);

namespace App\Commands\Php;

use App\Commands\Concerns\ResolvesHostContext;
use App\Commands\GatewayCommand;
use App\Exceptions\GatewayApiException;
use App\Exceptions\OrbitConfigStoreException;

final class PhpListCommand extends GatewayCommand
{
    use ResolvesHostContext;

    #[\Override]
    protected $signature = 'php:list
        {--app= : App selector}
        {--workspace= : Workspace selector}
        {--node= : Node selector}
        {--live : Inspect available PHP images live}
        {--json}';

    #[\Override]
    protected $description = 'List PHP runtime support and selected runtime intent.';

    public function handle(): int
    {
        try {
            $app = $this->stringOption('app') ?? $this->appFromOrbitMarker();
            $workspace = $this->stringOption('workspace');
            $node = $this->stringOption('node');

            if ($node === null && $app === null && $workspace === null) {
                $node = $this->targetNodeOptionOrDefault();
            }

            $response = $this->gatewayGet('/api/php/runtime', $this->filledQuery([
                'app' => $app,
                'workspace' => $workspace,
                'node' => $node,
                'live' => $this->option('live') === true ? '1' : null,
            ]));
        } catch (OrbitConfigStoreException $exception) {
            return $this->renderFailure($exception->orbitCode, $exception->getMessage());
        } catch (GatewayApiException $exception) {
            return $this->renderGatewayFailure($exception);
        }

        return $this->renderSuccess($response);
    }
}
