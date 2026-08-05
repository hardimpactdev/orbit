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
use App\Services\ApplicationLogs\ApplicationLogProxyTarget;
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
        ApplicationLogProxyTarget $proxyTarget,
        ApplicationLogTargetEndpoints $endpoints,
    ): int {
        $flags = $this->parseApplicationLogFlags();

        if (is_int($flags)) {
            return $flags;
        }

        $target = $this->stringArgument('target');

        if ($target === null) {
            return $this->inferFromCwd($flags, $cwdInference, $endpoints);
        }

        $isUrl = str_contains($target, '://');

        // Bare token that exactly matches a registered app.instance is never a host selector.
        if (! $isUrl && $this->bareSelectorIsRegisteredInstance($target, $gatewayClient)) {
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

        $resolved = $this->resolveProxyHost($host['host'], $gatewayClient, $proxyTarget);

        if (is_int($resolved)) {
            return $resolved;
        }

        return $this->readOrFollow($resolved, $flags, $endpoints);
    }

    private function inferFromCwd(
        ApplicationLogFlags $flags,
        ApplicationLogCwdInference $cwdInference,
        ApplicationLogTargetEndpoints $endpoints,
    ): int {
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
        return $this->readOrFollow($inferred, $flags, $endpoints);
    }

    /**
     * @param  array{type: 'instance', selector: string}|array{type: 'workspace', workspace: string, instance: string}  $resolved
     */
    private function readOrFollow(
        array $resolved,
        ApplicationLogFlags $flags,
        ApplicationLogTargetEndpoints $endpoints,
    ): int {
        $target = $endpoints->forResolved($resolved, $flags);

        if ($flags->follow) {
            return $this->followApplicationLog($target['stream'], $target['query']);
        }

        try {
            $response = $this->gatewayGet($target['path'], $target['query']);
        } catch (GatewayApiException $exception) {
            return $this->renderGatewayFailure($exception);
        }

        if ($this->wantsJson()) {
            return $this->renderSuccess($response);
        }

        return $this->renderApplicationLogLines($response);
    }
}
