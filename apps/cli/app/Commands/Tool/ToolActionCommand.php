<?php

declare(strict_types=1);

namespace App\Commands\Tool;

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

        $instancePayload = $this->resolveToolInstancePayload($tool, $payload);

        if (is_int($instancePayload)) {
            return $instancePayload;
        }

        return $this->streamToolAction($tool, $this->action(), [
            ...$payload,
            ...$instancePayload,
        ]);
    }
}
