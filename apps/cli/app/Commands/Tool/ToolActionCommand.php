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

        return $this->streamToolAction($tool, $this->action(), [
            ...$payload,
            ...$this->filledQuery([
                'instance' => $this->stringOption('instance'),
            ]),
        ]);
    }
}
