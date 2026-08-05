<?php

declare(strict_types=1);

namespace App\Commands\Workspace;

use App\Commands\Concerns\ReadsApplicationLogs;
use App\Commands\Concerns\ResolvesHostContext;
use App\Commands\GatewayCommand;
use App\Exceptions\GatewayApiException;
use App\Services\ApplicationLogs\ApplicationLogCwdInference;
use App\Services\ApplicationLogs\ApplicationLogFlags;
use App\Services\ApplicationLogs\ApplicationLogGatewayClient;
use App\Services\ApplicationLogs\ApplicationLogInstanceSelector;
use App\Services\ApplicationLogs\ApplicationLogRequestedTarget;

final class WorkspaceLogCommand extends GatewayCommand
{
    use ReadsApplicationLogs;
    use ResolvesHostContext;

    #[\Override]
    protected $signature = 'workspace:log
        {target? : Workspace name or workspace URL/hostname}
        {--instance= : Parent instance selector (app.instance) when the workspace slug is ambiguous}
        {--lines=100 : Number of historical lines}
        {--follow : Follow log output}
        {--node= : Serving node constraint}
        {--json}';

    #[\Override]
    protected $description = 'Read or follow the fixed Laravel application log for a Workspace.';

    public function handle(
        ApplicationLogInstanceSelector $selectors,
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

        if (str_contains($target, '://') || $this->looksLikeBareHostname($target)) {
            return $this->fromUrlOrHost($target, $flags, $gatewayClient);
        }

        return $this->fromWorkspaceName($target, $flags, $selectors, $gatewayClient);
    }

    private function looksLikeBareHostname(string $target): bool
    {
        return (
            str_contains($target, '.')
            && ! str_contains($target, '/')
            && ! str_contains($target, '@')
            && ! str_contains($target, '?')
        );
    }

    private function inferFromCwd(ApplicationLogFlags $flags, ApplicationLogCwdInference $cwdInference): int
    {
        if ($this->wantsJson() || ! $this->input->isInteractive()) {
            return $this->renderFailure('validation_failed', 'A workspace target is required.', [
                'field' => 'target',
            ]);
        }

        $cwd = $this->hostCwd();

        if ($cwd === null) {
            return $this->renderFailure(
                'validation_failed',
                'No unambiguous workspace target could be inferred from the current directory.',
                ['field' => 'target', 'reason' => 'cwd_target_missing'],
            );
        }

        try {
            $response = $this->gatewayGet('/api/workspaces/resolve-by-path', ['path' => $cwd]);
        } catch (GatewayApiException $exception) {
            return $this->renderGatewayFailure($exception);
        }

        $inferred = $cwdInference->forWorkspaceLog($this->applicationLogSuccessData($response));

        if (isset($inferred['error'])) {
            return $this->renderFailure('validation_failed', $inferred['error'], [
                'field' => 'target',
                'reason' => $inferred['reason'],
            ]);
        }

        $workspace = $inferred['workspace'] ?? null;
        $instance = $inferred['instance'] ?? null;

        if (! is_string($workspace) || ! is_string($instance)) {
            return $this->renderFailure(
                'validation_failed',
                'No unambiguous workspace target could be inferred from the current directory.',
                ['field' => 'target', 'reason' => 'cwd_target_missing'],
            );
        }

        return $this->readOrFollow($workspace, $instance, $flags, $workspace);
    }

    private function fromUrlOrHost(
        string $target,
        ApplicationLogFlags $flags,
        ApplicationLogGatewayClient $gatewayClient,
    ): int {
        $host = $this->parseApplicationLogHost($target);

        if (is_int($host)) {
            return $host;
        }

        try {
            $response = $this->gatewayGet('/api/proxy-routes');
        } catch (GatewayApiException $exception) {
            return $this->renderGatewayFailure($exception);
        }

        $matched = $gatewayClient->matchProxyHost(
            $host['host'],
            $gatewayClient->routeList($this->applicationLogSuccessData($response)),
        );

        if ($matched['ok'] === false) {
            return $this->renderFailure('validation_failed', $matched['message'], array_merge(
                ['field' => $matched['field']],
                $matched['meta'],
            ));
        }

        if ($matched['type'] !== 'workspace') {
            return $this->renderFailure(
                'validation_failed',
                'The host resolves to an instance. Use instance:log or app:log.',
                ['field' => 'target', 'host' => $host['host'], 'reason' => 'wrong_target_type'],
            );
        }

        $workspace = $matched['workspace'] ?? null;
        $instance = $matched['instance'] ?? null;

        if (! is_string($workspace) || ! is_string($instance)) {
            return $this->renderFailure(
                'validation_failed',
                'The workspace proxy route did not include a parent instance selector.',
                ['field' => 'instance', 'host' => $host['host']],
            );
        }

        return $this->readOrFollow($workspace, $instance, $flags, $host['host']);
    }

    private function fromWorkspaceName(
        string $workspace,
        ApplicationLogFlags $flags,
        ApplicationLogInstanceSelector $selectors,
        ApplicationLogGatewayClient $gatewayClient,
    ): int {
        $instanceOption = $this->stringOption('instance');

        if ($instanceOption !== null) {
            $instance = $selectors->parse($instanceOption);

            if ($instance['ok'] === false) {
                return $this->renderFailure('validation_failed', $instance['message'], [
                    'field' => $instance['field'],
                    'value' => $instanceOption,
                ]);
            }

            return $this->readOrFollow($workspace, $instance['selector'], $flags, $workspace);
        }

        try {
            $response = $this->gatewayGet('/api/workspaces');
        } catch (GatewayApiException $exception) {
            return $this->renderGatewayFailure($exception);
        }

        $resolved = $gatewayClient->resolveWorkspaceSlug(
            $workspace,
            $this->applicationLogSuccessData($response),
        );

        if ($resolved['ok'] === false) {
            if ($resolved['reason'] === 'workspace_not_found') {
                return $this->renderFailure(
                    'workspace.not_found',
                    "Workspace '{$workspace}' not found.",
                    ['field' => 'target', 'workspace' => $workspace],
                );
            }

            return $this->renderFailure(
                'validation_failed',
                'A parent --instance=<app.instance> is required when the workspace slug is ambiguous.',
                [
                    'field' => 'instance',
                    'workspace' => $workspace,
                    'reason' => 'workspace_slug_ambiguous',
                    'count' => $resolved['count'],
                ],
            );
        }

        return $this->readOrFollow($resolved['workspace'], $resolved['instance'], $flags, $workspace);
    }

    private function readOrFollow(
        string $workspace,
        string $instance,
        ApplicationLogFlags $flags,
        string $requestedTarget,
    ): int {
        $query = $flags->query(['instance' => $instance]);
        $headers = ApplicationLogRequestedTarget::headers($requestedTarget);

        if ($flags->follow) {
            return $this->followApplicationLog(
                '/api/workspaces/'.rawurlencode($workspace).'/log-stream',
                $query,
                $headers,
            );
        }

        try {
            $response = $this->gatewayGet(
                '/api/workspaces/'.rawurlencode($workspace).'/log',
                $query,
                $headers,
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
