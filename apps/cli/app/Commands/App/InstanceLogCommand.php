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
use App\Services\ApplicationLogs\ApplicationLogInstanceSelector;
use App\Services\ApplicationLogs\ApplicationLogInstanceTargetResolver;
use App\Services\ApplicationLogs\ApplicationLogInteractiveCwd;
use App\Services\ApplicationLogs\ApplicationLogRequestedTarget;

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
        ApplicationLogCwdInference $cwdInference,
        ApplicationLogInteractiveCwd $interactiveCwd,
        ApplicationLogInstanceTargetResolver $targets,
    ): int {
        $flags = $this->parseApplicationLogFlags();

        if (is_int($flags)) {
            return $flags;
        }

        $target = $this->stringArgument('target');

        if ($target === null) {
            return $this->inferFromCwd($flags, $cwdInference, $interactiveCwd);
        }

        $canonical = $selectors->parse($target);

        if ($canonical['ok'] === true) {
            return $this->readOrFollow($canonical['selector'], $flags, $canonical['selector']);
        }

        return $this->fromHostTarget($target, $flags, $gatewayClient, $targets);
    }

    private function inferFromCwd(
        ApplicationLogFlags $flags,
        ApplicationLogCwdInference $cwdInference,
        ApplicationLogInteractiveCwd $interactiveCwd,
    ): int {
        if ($this->wantsJson() || ! $this->input->isInteractive()) {
            return $this->renderFailure('validation_failed', 'An instance target is required.', [
                'field' => 'target',
            ]);
        }

        $cwd = $this->hostCwd();

        if ($cwd === null) {
            return $this->renderFailure(
                'validation_failed',
                'No unambiguous instance target could be inferred from the current directory.',
                ['field' => 'target', 'reason' => 'cwd_target_missing'],
            );
        }

        try {
            $response = $this->gatewayGet('/api/instances');
        } catch (GatewayApiException $exception) {
            return $this->renderGatewayFailure($exception);
        }

        $normalized = $interactiveCwd->normalizeInstanceLog($cwdInference->forInstanceLog(
            $this->applicationLogSuccessData($response),
            $cwd,
        ));

        if ($normalized['ok'] === false) {
            return $this->renderFailure('validation_failed', $normalized['message'], [
                'field' => 'target',
                'reason' => $normalized['reason'],
            ]);
        }

        return $this->readOrFollow($normalized['selector'], $flags, $normalized['selector']);
    }

    private function fromHostTarget(
        string $target,
        ApplicationLogFlags $flags,
        ApplicationLogGatewayClient $gatewayClient,
        ApplicationLogInstanceTargetResolver $targets,
    ): int {
        try {
            $response = $this->gatewayGet('/api/proxy-routes');
        } catch (GatewayApiException $exception) {
            return $this->renderGatewayFailure($exception);
        }

        $resolved = $targets->resolve(
            $target,
            $gatewayClient->routeList($this->applicationLogSuccessData($response)),
        );

        if ($resolved['ok'] === false) {
            return $this->renderFailure(
                'validation_failed',
                $resolved['message'],
                array_merge(['field' => $resolved['field']], $resolved['meta']),
            );
        }

        return $this->readOrFollow($resolved['selector'], $flags, $resolved['requested_target']);
    }

    private function readOrFollow(string $selector, ApplicationLogFlags $flags, string $requestedTarget): int
    {
        $query = $flags->query();
        $headers = ApplicationLogRequestedTarget::headers($requestedTarget);

        if ($flags->follow) {
            return $this->followApplicationLog(
                '/api/instances/'.rawurlencode($selector).'/log-stream',
                $query,
                $headers,
            );
        }

        try {
            $response = $this->gatewayGet(
                '/api/instances/'.rawurlencode($selector).'/log',
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
