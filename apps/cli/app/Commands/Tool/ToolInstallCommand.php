<?php

declare(strict_types=1);

namespace App\Commands\Tool;

use App\Exceptions\GatewayApiException;

final class ToolInstallCommand extends ToolGatewayCommand
{
    private const array STATUSES = ['installed', 'running'];

    protected $signature = 'tool:install
        {tool? : Tool catalog name to install}
        {--app= : Resolve target by app selector}
        {--node= : Resolve target by node}
        {--status=installed : Desired state after install (installed|running)}
        {--json : Output JSON}';

    protected $description = 'Install a managed tool through the gateway.';

    public function handle(): int
    {
        $tool = $this->requireToolArgument();

        if (is_int($tool)) {
            return $tool;
        }

        $status = (string) $this->option('status');

        if (! in_array($status, self::STATUSES, true)) {
            return $this->failValidation('status', "Invalid --status value '{$status}'. Valid values: installed, running.", [
                'value' => $status,
                'reason' => 'unsupported_value',
            ]);
        }

        $payload = $this->toolTargetPayload(requireTarget: true);

        if (is_int($payload)) {
            return $payload;
        }

        try {
            $response = $this->gatewayPost('/api/tools/'.rawurlencode($tool).'/install', [
                ...$payload,
                'status' => $status,
            ]);
        } catch (GatewayApiException $exception) {
            return $this->renderGatewayFailure($exception);
        }

        return $this->renderSuccess($response);
    }
}
