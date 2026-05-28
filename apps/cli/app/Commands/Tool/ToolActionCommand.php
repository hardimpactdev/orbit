<?php

declare(strict_types=1);

namespace App\Commands\Tool;

use App\Exceptions\GatewayApiException;

abstract class ToolActionCommand extends ToolGatewayCommand
{
    abstract protected function action(): string;

    public function handle(): int
    {
        $tool = $this->requireToolArgument();

        if (is_int($tool)) {
            return $tool;
        }

        $payload = $this->toolTargetPayload();

        if (is_int($payload)) {
            return $payload;
        }

        try {
            $response = $this->gatewayPost('/api/tools/'.rawurlencode($tool).'/'.$this->action(), $payload);
        } catch (GatewayApiException $exception) {
            return $this->renderGatewayFailure($exception);
        }

        return $this->renderSuccess($response);
    }
}
