<?php

declare(strict_types=1);

namespace App\Commands\Tool;

use App\Commands\Concerns\ResolvesHostContext;
use App\Commands\GatewayCommand;
use App\Exceptions\GatewayApiException;
use App\Exceptions\OrbitConfigStoreException;

final class ToolLogsCommand extends GatewayCommand
{
    use ResolvesHostContext;

    protected $signature = 'tool:logs
        {tool? : Tool catalog name to read logs for}
        {--app= : Resolve target by app selector}
        {--node= : Resolve target by node}
        {--follow : Follow log output}
        {--lines=100 : Number of historical lines}
        {--json}';

    protected $description = 'Read managed tool logs (non-follow).';

    public function handle(): int
    {
        $tool = $this->stringArgument('tool');

        if ($tool === null) {
            return $this->renderFailure('validation_failed', 'The tool argument is required.', ['field' => 'tool']);
        }

        if ($this->option('follow') === true) {
            return $this->renderFailure('validation_failed', 'Follow mode is handled by the gateway bridge until streaming is ported.', ['field' => 'follow']);
        }

        $lines = $this->lines();

        if ($lines === null) {
            return $this->renderFailure('validation_failed', 'The --lines value must be a positive integer.', ['field' => 'lines']);
        }

        try {
            $response = $this->gatewayGet('/api/tools/'.rawurlencode($tool).'/logs', $this->filledQuery([
                'app' => $this->stringOption('app'),
                'node' => $this->targetNodeOptionOrDefault(),
                'lines' => $lines,
            ]));
        } catch (OrbitConfigStoreException $exception) {
            return $this->renderFailure($exception->orbitCode, $exception->getMessage());
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
        $data = $this->successData($response);
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

            $this->line((string) ($line['message'] ?? ''));
        }
    }

    /**
     * @param  array<string, mixed>  $response
     * @return array<string, mixed>
     */
    private function successData(array $response): array
    {
        $success = $response['success'] ?? null;

        if (is_array($success) && is_array($success['data'] ?? null)) {
            return $success['data'];
        }

        return $response;
    }
}
