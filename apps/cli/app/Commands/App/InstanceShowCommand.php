<?php

declare(strict_types=1);

namespace App\Commands\App;

use App\Exceptions\GatewayApiException;

final class InstanceShowCommand extends InstanceCommand
{
    #[\Override]
    protected $signature = 'instance:show {instance? : app.instance selector} {--json : Output JSON}';

    #[\Override]
    protected $description = 'Show one instance.';

    public function handle(): int
    {
        $selector = $this->resolveInstanceSelector();

        if (is_int($selector)) {
            return $selector;
        }

        try {
            $response = $this->gatewayGet($this->apiProjectPath(
                $selector['app'],
                '/instances/'.rawurlencode($selector['instance']),
            ));
        } catch (GatewayApiException $exception) {
            return $this->renderGatewayFailure($exception);
        }

        if ($this->wantsJson()) {
            return $this->renderSuccess($response);
        }

        $instance = $this->instanceFromGatewayResponse($response);

        if ($instance === null) {
            return $this->renderFailure('gateway_unavailable', 'Gateway response missing required instance data.');
        }

        $this->renderInstance($instance);

        return self::SUCCESS;
    }
}
