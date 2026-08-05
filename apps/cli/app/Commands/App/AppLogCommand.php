<?php

declare(strict_types=1);

namespace App\Commands\App;

use App\Commands\Concerns\ReadsApplicationLogs;
use App\Commands\Concerns\ResolvesHostContext;
use App\Commands\GatewayCommand;
use App\Exceptions\GatewayApiException;
use App\Services\ApplicationLogs\ApplicationLogCwdInference;
use App\Services\ApplicationLogs\ApplicationLogFlags;
use App\Services\ApplicationLogs\ApplicationLogGatewayClient;

final class AppLogCommand extends GatewayCommand
{
    use ReadsApplicationLogs;
    use ResolvesHostContext;

    #[\Override]
    protected $signature = 'app:log
        {target? : Instance or workspace URL/hostname}
        {--lines=100 : Number of historical lines}
        {--follow : Follow log output}
        {--node= : Serving node constraint}
        {--json}';

    #[\Override]
    protected $description = 'Read or follow the fixed Laravel application log resolved from a proxy URL or hostname.';

    public function handle(
        ApplicationLogGatewayClient $gatewayClient,
        ApplicationLogCwdInference $cwdInference,
    ): int {
        $flags = $this->parseApplicationLogFlags();

        if (is_int($flags)) {
            return $flags;
        }

        $target = $this->stringArgument('target');

        if ($target === null) {
            return $this->inferFromCwd($flags, $cwdInference);
        }

        $isUrl = str_contains($target, '://');

        // Bare token that exactly matches a registered app.instance is never a host selector.
        if (! $isUrl && $this->bareTokenIsRegisteredInstance($target, $gatewayClient)) {
            return $this->renderFailure(
                'validation_failed',
                'app:log accepts only a URL or hostname. Use instance:log for app.instance selectors.',
                ['field' => 'target', 'value' => $target],
            );
        }

        $host = $this->parseApplicationLogHost($target);

        if (is_int($host)) {
            return $host;
        }

        $resolved = $this->resolveProxyHost($host['host'], $gatewayClient);

        if (is_int($resolved)) {
            return $resolved;
        }

        return $this->readOrFollow($resolved, $flags);
    }

    private function inferFromCwd(ApplicationLogFlags $flags, ApplicationLogCwdInference $cwdInference): int
    {
        if ($this->wantsJson() || ! $this->input->isInteractive()) {
            return $this->renderFailure('validation_failed', 'A URL or hostname target is required.', [
                'field' => 'target',
            ]);
        }

        $workspaceData = $this->workspaceDataForCwd();
        $inferred = $cwdInference->forAppLog($workspaceData, $this->instanceFromOrbitMarker());

        if (isset($inferred['error'])) {
            return $this->renderFailure('validation_failed', $inferred['error'], [
                'field' => 'target',
                'reason' => $inferred['reason'],
            ]);
        }

        /** @var array{type: 'instance', selector: string}|array{type: 'workspace', workspace: string, instance: string} $inferred */
        return $this->readOrFollow($inferred, $flags);
    }

    /**
     * @return array<string, mixed>|null
     */
    private function workspaceDataForCwd(): ?array
    {
        $cwd = $this->hostCwd();

        if ($cwd === null) {
            return null;
        }

        try {
            $response = $this->gatewayGet('/api/workspaces/resolve-by-path', [
                'path' => $cwd,
            ]);
        } catch (GatewayApiException) {
            return null;
        }

        return $this->applicationLogSuccessData($response);
    }

    private function bareTokenIsRegisteredInstance(string $token, ApplicationLogGatewayClient $gatewayClient): bool
    {
        try {
            $response = $this->gatewayGet('/api/instances');
        } catch (GatewayApiException) {
            return false;
        }

        return $gatewayClient->isRegisteredInstanceSelector(
            $token,
            $this->applicationLogSuccessData($response),
        );
    }

    /**
     * @return array{type: 'instance', selector: string}|array{type: 'workspace', workspace: string, instance: string}|int
     */
    private function resolveProxyHost(string $host, ApplicationLogGatewayClient $gatewayClient): array|int
    {
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
            return $this->renderFailure('validation_failed', $matched['message'], array_merge(
                ['field' => $matched['field']],
                $matched['meta'],
            ));
        }

        if ($matched['type'] === 'workspace') {
            return [
                'type' => 'workspace',
                'workspace' => $matched['workspace'],
                'instance' => $matched['instance'],
            ];
        }

        return [
            'type' => 'instance',
            'selector' => $matched['selector'],
        ];
    }

    /**
     * @param  array{type: 'instance', selector: string}|array{type: 'workspace', workspace: string, instance: string}  $resolved
     */
    private function readOrFollow(array $resolved, ApplicationLogFlags $flags): int
    {
        if ($resolved['type'] === 'workspace') {
            $path = '/api/workspaces/'.rawurlencode($resolved['workspace']).'/log';
            $stream = '/api/workspaces/'.rawurlencode($resolved['workspace']).'/log-stream';
            $query = $flags->query(['instance' => $resolved['instance']]);
        } else {
            $path = '/api/instances/'.rawurlencode($resolved['selector']).'/log';
            $stream = '/api/instances/'.rawurlencode($resolved['selector']).'/log-stream';
            $query = $flags->query();
        }

        if ($flags->follow) {
            return $this->followApplicationLog($stream, $query);
        }

        try {
            $response = $this->gatewayGet($path, $query);
        } catch (GatewayApiException $exception) {
            return $this->renderGatewayFailure($exception);
        }

        if ($this->wantsJson()) {
            return $this->renderSuccess($response);
        }

        return $this->renderApplicationLogLines($response);
    }
}
