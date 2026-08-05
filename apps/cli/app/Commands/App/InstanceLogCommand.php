<?php

declare(strict_types=1);

namespace App\Commands\App;

use App\Commands\Concerns\ReadsApplicationLogs;
use App\Commands\Concerns\ResolvesHostContext;
use App\Commands\GatewayCommand;
use App\Exceptions\GatewayApiException;
use App\Services\ApplicationLogs\ApplicationLogFlags;
use App\Services\ApplicationLogs\ApplicationLogGatewayClient;
use App\Services\ApplicationLogs\ApplicationLogInstanceSelector;

final class InstanceLogCommand extends GatewayCommand
{
    use ReadsApplicationLogs;
    use ResolvesHostContext;

    #[\Override]
    protected $signature = 'instance:log
        {target? : Instance selector (app.instance) or instance URL/hostname}
        {--lines=100 : Number of historical lines}
        {--follow : Follow log output}
        {--node= : Serving node constraint}
        {--json}';

    #[\Override]
    protected $description = 'Read or follow the fixed Laravel application log for an Instance.';

    public function handle(
        ApplicationLogInstanceSelector $selectors,
        ApplicationLogGatewayClient $gatewayClient,
    ): int {
        $flags = $this->parseApplicationLogFlags();

        if (is_int($flags)) {
            return $flags;
        }

        $target = $this->stringArgument('target');

        if ($target === null) {
            return $this->inferFromCwd($flags, $selectors);
        }

        $selector = $this->normalizeInstanceTarget($target, $selectors, $gatewayClient);

        if (is_int($selector)) {
            return $selector;
        }

        return $this->readOrFollow($selector, $flags);
    }

    private function inferFromCwd(ApplicationLogFlags $flags, ApplicationLogInstanceSelector $selectors): int
    {
        if ($this->wantsJson() || ! $this->input->isInteractive()) {
            return $this->renderFailure('validation_failed', 'An instance target is required.', [
                'field' => 'target',
            ]);
        }

        $marker = $this->instanceFromOrbitMarker();

        if ($marker === null) {
            return $this->renderFailure(
                'validation_failed',
                'No unambiguous instance target could be inferred from the current directory.',
                ['field' => 'target', 'reason' => 'cwd_target_missing'],
            );
        }

        $parsed = $selectors->parse($marker);

        if ($parsed['ok'] === false) {
            return $this->renderFailure('validation_failed', $parsed['message'], [
                'field' => $parsed['field'],
                'value' => $marker,
            ]);
        }

        return $this->readOrFollow($parsed['selector'], $flags);
    }

    private function normalizeInstanceTarget(
        string $target,
        ApplicationLogInstanceSelector $selectors,
        ApplicationLogGatewayClient $gatewayClient,
    ): string|int {
        // 1) Canonical lowercase one-dot app.instance is always a selector.
        $canonical = $selectors->parse($target);

        if ($canonical['ok'] === true) {
            return $canonical['selector'];
        }

        // 2) Otherwise treat as URL/hostname (mixed case and multi-label allowed; normalized lower).
        $host = $this->parseApplicationLogHost($target);

        if (is_int($host)) {
            // Not a valid host shape either: keep the host-parse error (credentials, path, etc.).
            return $host;
        }

        // 3) Valid host shapes always report proxy-route resolution outcomes (including
        // unregistered multi-label hosts). Never fall back to the canonical-selector error.
        return $this->resolveRegisteredInstanceHost($host['host'], $gatewayClient);
    }

    /**
     * @return string|int string selector on success, int exit code on failure
     */
    private function resolveRegisteredInstanceHost(
        string $host,
        ApplicationLogGatewayClient $gatewayClient,
    ): string|int {
        try {
            $response = $this->gatewayGet('/api/proxy-routes');
        } catch (GatewayApiException $exception) {
            return $this->renderGatewayFailure($exception);
        }

        $matched = $gatewayClient->matchProxyHost(
            $host,
            $gatewayClient->routeList($this->applicationLogSuccessData($response)),
        );

        if ($matched['ok'] === false) {
            return $this->renderFailure(
                'validation_failed',
                $matched['message'],
                array_merge(
                    ['field' => $matched['field']],
                    $matched['meta'],
                ),
            );
        }

        if ($matched['type'] === 'workspace') {
            return $this->renderFailure(
                'validation_failed',
                'The host resolves to a workspace. Use workspace:log or app:log.',
                ['field' => 'target', 'host' => $host, 'reason' => 'wrong_target_type'],
            );
        }

        return $matched['selector'];
    }

    private function readOrFollow(string $selector, ApplicationLogFlags $flags): int
    {
        $query = $flags->query();

        if ($flags->follow) {
            return $this->followApplicationLog(
                '/api/instances/'.rawurlencode($selector).'/log-stream',
                $query,
            );
        }

        try {
            $response = $this->gatewayGet(
                '/api/instances/'.rawurlencode($selector).'/log',
                $query,
            );
        } catch (GatewayApiException $exception) {
            return $this->renderGatewayFailure($exception);
        }

        if ($this->wantsJson()) {
            return $this->renderSuccess($response);
        }

        return $this->renderApplicationLogLines($response);
    }
}
