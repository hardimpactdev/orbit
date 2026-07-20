<?php

declare(strict_types=1);

namespace App\Commands\Tool;

use App\Exceptions\GatewayApiException;

/** @mago-expect lint:cyclomatic-complexity */
final class ToolLogsCommand extends ToolGatewayCommand
{
    #[\Override]
    protected $signature = 'tool:logs
        {tool? : Tool catalog name whose logs should be read}
        {--instance= : Resolve target by instance selector}
        {--node= : Resolve target by node}
        {--lines=100 : Number of historical lines}
        {--json : Output JSON}';

    #[\Override]
    protected $description = 'Read logs from a log-capable managed tool.';

    public function handle(): int
    {
        $tool = $this->requireToolArgument();

        if (is_int($tool)) {
            return $tool;
        }

        $target = $this->toolTargetPayload(requireTarget: true);

        if (is_int($target)) {
            return $target;
        }

        $lines = $this->lines();

        if ($lines === null) {
            return $this->failValidation('lines', 'The --lines value must be a positive integer.');
        }

        try {
            $response = $this->gatewayGet(
                '/api/tools/'.rawurlencode($tool).'/logs',
                [...$target, 'lines' => $lines],
            );
        } catch (GatewayApiException $exception) {
            return $this->renderGatewayFailure($exception);
        }

        if ($this->wantsJson()) {
            return $this->renderSuccess($response);
        }

        $this->renderLogLines($response);

        return self::SUCCESS;
    }

    private function lines(): ?int
    {
        $value = $this->option('lines');

        if (! is_numeric($value)) {
            return null;
        }

        $lines = (int) $value;

        return $lines > 0 ? $lines : null;
    }

    /**
     * @param  array<string, mixed>  $response
     */
    private function renderLogLines(array $response): void
    {
        $success = is_array($response['success'] ?? null) ? $response['success'] : [];
        $data = is_array($success['data'] ?? null) ? $success['data'] : $response;
        $logs = is_array($data['logs'] ?? null) ? $data['logs'] : [];
        $lines = is_array($logs['lines'] ?? null) ? $logs['lines'] : [];

        if ($lines === []) {
            $this->line('No log lines found.');

            return;
        }

        foreach ($lines as $line) {
            if (! is_array($line)) {
                continue;
            }

            $timestamp = is_string($line['timestamp'] ?? null) && trim($line['timestamp']) !== ''
                ? trim($line['timestamp']).' '
                : '';

            $this->line($timestamp.(string) ($line['message'] ?? ''));
        }
    }
}
