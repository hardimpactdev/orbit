<?php

declare(strict_types=1);

namespace App\Commands\App;

use App\Commands\Concerns\ReadsApplicationLogs;
use App\Commands\Concerns\ResolvesApplicationLogProxyTargets;
use App\Commands\Concerns\ResolvesHostContext;
use App\Commands\GatewayCommand;
use App\Exceptions\GatewayApiException;
use App\Services\ApplicationLogs\ApplicationLogCwdInference;
use App\Services\ApplicationLogs\ApplicationLogFlags;
use App\Services\ApplicationLogs\ApplicationLogGatewayClient;
use App\Services\ApplicationLogs\ApplicationLogInteractiveCwd;
use App\Services\ApplicationLogs\ApplicationLogProxyTarget;
use App\Services\ApplicationLogs\ApplicationLogRequestedTarget;
use App\Services\ApplicationLogs\ApplicationLogTargetEndpoints;

final class AppLogCommand extends GatewayCommand
{
    use ReadsApplicationLogs;
    use ResolvesApplicationLogProxyTargets;
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
        ApplicationLogInteractiveCwd $interactiveCwd,
        ApplicationLogProxyTarget $proxyTarget,
        ApplicationLogTargetEndpoints $endpoints,
    ): int {
        $flags = $this->parseApplicationLogFlags();

        if (is_int($flags)) {
            return $flags;
        }

        $target = $this->stringArgument('target');

        if ($target === null) {
            return $this->inferFromCwd($flags, $cwdInference, $interactiveCwd, $endpoints);
        }

        return $this->fromHostTarget($target, $flags, $gatewayClient, $proxyTarget, $endpoints);
    }

    private function fromHostTarget(
        string $target,
        ApplicationLogFlags $flags,
        ApplicationLogGatewayClient $gatewayClient,
        ApplicationLogProxyTarget $proxyTarget,
        ApplicationLogTargetEndpoints $endpoints,
    ): int {
        $host = $this->parseApplicationLogHost($target);

        if (is_int($host)) {
            return $host;
        }

        $resolved = $this->resolveProxyHost($host['host'], $gatewayClient, $proxyTarget);

        if (is_int($resolved)) {
            return $resolved;
        }

        return $this->readOrFollow($resolved, $flags, $endpoints, $host['host']);
    }

    private function inferFromCwd(
        ApplicationLogFlags $flags,
        ApplicationLogCwdInference $cwdInference,
        ApplicationLogInteractiveCwd $interactiveCwd,
        ApplicationLogTargetEndpoints $endpoints,
    ): int {
        if ($this->wantsJson() || ! $this->input->isInteractive()) {
            return $this->renderFailure('validation_failed', 'A URL or hostname target is required.', [
                'field' => 'target',
            ]);
        }

        $cwd = $this->hostCwd();

        if ($cwd === null) {
            return $this->renderFailure(
                'validation_failed',
                'No unambiguous application-log target could be inferred from the current directory.',
                ['field' => 'target', 'reason' => 'cwd_target_missing'],
            );
        }

        try {
            $instancesResponse = $this->gatewayGet('/api/instances');
        } catch (GatewayApiException $exception) {
            return $this->renderGatewayFailure($exception);
        }

        $normalized = $interactiveCwd->normalizeAppLog($cwdInference->forAppLog(
            $this->workspaceDataForCwd(),
            $this->applicationLogSuccessData($instancesResponse),
            $cwd,
        ));

        if ($normalized['ok'] === false) {
            return $this->renderFailure('validation_failed', $normalized['message'], [
                'field' => 'target',
                'reason' => $normalized['reason'],
            ]);
        }

        return $this->readOrFollow($normalized['target'], $flags, $endpoints, $normalized['requested']);
    }

    /**
     * @param  array{type: 'instance', selector: string}|array{type: 'workspace', workspace: string, instance: string}  $resolved
     */
    private function readOrFollow(
        array $resolved,
        ApplicationLogFlags $flags,
        ApplicationLogTargetEndpoints $endpoints,
        string $requestedTarget,
    ): int {
        $target = $endpoints->forResolved($resolved, $flags);
        $headers = ApplicationLogRequestedTarget::headers($requestedTarget);

        if ($flags->follow) {
            return $this->followApplicationLog($target['stream'], $target['query'], $headers);
        }

        try {
            $response = $this->gatewayGet($target['path'], $target['query'], $headers);
        } catch (GatewayApiException $exception) {
            return $this->renderGatewayFailure($exception);
        }

        if ($this->wantsJson()) {
            return $this->renderSuccess($response);
        }

        return $this->renderApplicationLogLines($response);
    }
}
