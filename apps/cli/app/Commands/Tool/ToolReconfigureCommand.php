<?php

declare(strict_types=1);

namespace App\Commands\Tool;

use App\Exceptions\GatewayApiException;

final class ToolReconfigureCommand extends ToolGatewayCommand
{
    protected $signature = 'tool:reconfigure
        {tool? : Tool catalog name to reconfigure}
        {--app= : Resolve target by app selector}
        {--node= : Resolve target by node}
        {--password= : Auth password (OpenCode Server)}
        {--json : Output JSON}';

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

        try {
            $response = $this->gatewayPost('/api/tools/'.rawurlencode($tool).'/reconfigure', [
                ...$payload,
                ...$this->filledQuery(['password' => $this->stringOption('password')]),
            ]);
        } catch (GatewayApiException $exception) {
            return $this->renderGatewayFailure($exception);
        }

        return $this->renderSuccess($response);
    }
}
