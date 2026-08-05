<?php

declare(strict_types=1);

namespace App\Commands\Cloudflare;

use App\Commands\Concerns\WithStepTree;
use App\Exceptions\GatewayApiException;
use RuntimeException;

final class CfCacheRuleAddCommand extends CloudflareGatewayCommand
{
    use WithStepTree;

    #[\Override]
    protected $signature = 'cf-cache-rule:add
        {app? : Orbit app name}
        {--json : Output JSON}';

    #[\Override]
    protected $description = 'Add or converge a Cloudflare cache rule for an app through the gateway.';

    public function handle(): int
    {
        if (($failure = $this->guardLocalExtension()) !== null) {
            return $failure;
        }

        $app = $this->requiredArgument('app', 'app', 'A app name is required.');

        if (is_int($app)) {
            return $app;
        }

        if ($this->wantsJson()) {
            try {
                $response = $this->addRule($app);
            } catch (GatewayApiException $exception) {
                return $this->renderGatewayFailure($exception);
            }

            return $this->renderSuccess($response);
        }

        return $this->renderAddTree($app);
    }

    private function renderAddTree(string $app): int
    {
        $response = [];

        $outcome = $this->runStepOperation(
            'Adding Cloudflare cache rule',
            [
                ['label' => 'Resolve project domain'],
                ['label' => 'Resolve Cloudflare zone'],
                ['label' => 'Write cache rule'],
            ],
            work: function () use ($app, &$response): array {
                return $response = $this->addRuleForHuman($app);
            },
            doneFooter: function () use ($app, &$response): string {
                $resolvedProject = $this->successData($response)['app'] ?? null;
                $displayProject = is_string($resolvedProject) && $resolvedProject !== ''
                    ? $resolvedProject
                    : $app;

                return "Cloudflare cache rule ready for {$displayProject}: respect origin Cache-Control";
            },
        );

        return $outcome->isCompleted() ? self::SUCCESS : self::FAILURE;
    }

    /**
     * @return array<string, mixed>
     */
    private function addRule(string $app): array
    {
        return $this->gatewayPost('/api/cloudflare/cache-rules/'.rawurlencode($app));
    }

    /**
     * @return array<string, mixed>
     */
    private function addRuleForHuman(string $app): array
    {
        try {
            return $this->addRule($app);
        } catch (GatewayApiException $exception) {
            throw new RuntimeException(
                $exception->gatewayErrorMessage() ?? $exception->getMessage(),
                previous: $exception,
            );
        }
    }

    /**
     * @param  array<string, mixed>  $response
     * @return array<string, mixed>
     */
    private function successData(array $response): array
    {
        $rule = $response['success']['data']['rule'] ?? null;

        return is_array($rule) ? $rule : [];
    }
}
