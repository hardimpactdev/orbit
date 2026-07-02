<?php

declare(strict_types=1);

namespace App\Commands\Tool;

use App\Exceptions\GatewayApiException;

abstract class ToolLifecycleCommand extends ToolGatewayCommand
{
    abstract protected function action(): string;

    public function handle(): int
    {
        if ($this->wantsJson() && $this->wantsStreamingJson()) {
            return $this->renderFailure(
                'validation_failed',
                'Use either --json or --stream-json, not both.',
                ['fields' => ['json', 'stream-json'], 'reason' => 'conflicting_options'],
            );
        }

        $tool = $this->requireToolArgument();

        if (is_int($tool)) {
            return $tool;
        }

        $payload = $this->toolTargetPayload(requireTarget: true);

        if (is_int($payload)) {
            return $payload;
        }

        if ($this->wantsJson()) {
            try {
                return $this->renderSuccess($this->gatewayPost(
                    '/api/tools/'.rawurlencode($tool).'/'.$this->action(),
                    $payload,
                ));
            } catch (GatewayApiException $exception) {
                return $this->renderGatewayFailure($exception);
            }
        }

        return $this->streamToolAction($tool, $this->action(), $payload);
    }
}
