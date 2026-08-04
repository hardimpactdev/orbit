<?php

declare(strict_types=1);

namespace App\Commands\Tool;

final class ToolReconfigureCommand extends ToolGatewayCommand
{
    #[\Override]
    protected $signature = 'tool:reconfigure
        {tool? : Tool catalog name to reconfigure}
        {--instance= : Resolve target by instance selector}
        {--node= : Resolve target by node}
        {--password= : Auth password when the tool requires one}
        {--json : Output JSON}
        {--stream-json : Stream newline-delimited JSON progress frames}';

    #[\Override]
    protected $description = 'Reconfigure a managed tool through the gateway.';

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

        return $this->streamToolAction($tool, 'reconfigure', [
            ...$payload,
            ...$this->filledQuery(['password' => $this->stringOption('password')]),
        ]);
    }
}
