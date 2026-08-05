<?php

declare(strict_types=1);

namespace App\Commands\Cloudflare;

use App\Commands\Concerns\WithStepTree;
use App\Exceptions\GatewayApiException;
use RuntimeException;

final class CfCacheRuleRemoveCommand extends CloudflareGatewayCommand
{
    use WithStepTree;

    #[\Override]
    protected $signature = 'cf-cache-rule:remove
        {app? : Orbit app name}
        {--force : Confirm destructive operation without prompting}
        {--json : Output JSON}';

    #[\Override]
    protected $description = 'Remove a Cloudflare cache rule for an app through the gateway.';

    public function handle(): int
    {
        if (($failure = $this->guardLocalExtension()) !== null) {
            return $failure;
        }

        $app = $this->requiredArgument('app', 'app', 'A app name is required.');

        if (is_int($app)) {
            return $app;
        }

        $consent = $this->confirmDestructive(
            'Removing a Cloudflare cache rule requires --force in non-interactive mode.',
        );

        if (is_int($consent)) {
            return $consent;
        }

        if ($this->wantsJson()) {
            try {
                $response = $this->removeRule($app);
            } catch (GatewayApiException $exception) {
                return $this->renderGatewayFailure($exception);
            }

            return $this->renderSuccess($response);
        }

        return $this->renderRemoveTree($app);
    }

    private function renderRemoveTree(string $app): int
    {
        $response = [];

        $outcome = $this->runStepOperation(
            'Removing Cloudflare cache rule',
            [
                ['label' => 'Resolve project domain'],
                ['label' => 'Resolve Cloudflare zone'],
                ['label' => 'Delete cache rule'],
            ],
            work: function () use ($app, &$response): array {
                return $response = $this->removeRuleForHuman($app);
            },
            doneFooter: function () use ($app, &$response): string {
                $resolvedProject = $this->successData($response)['app'] ?? null;
                $displayProject = is_string($resolvedProject) && $resolvedProject !== ''
                    ? $resolvedProject
                    : $app;

                return "Cloudflare cache rule removed for {$displayProject}";
            },
        );

        return $outcome->isCompleted() ? self::SUCCESS : self::FAILURE;
    }

    /**
     * @return array<string, mixed>
     */
    private function removeRule(string $app): array
    {
        return $this->gatewayDelete('/api/cloudflare/cache-rules/'.rawurlencode($app), [
            'destructive_consent' => true,
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function removeRuleForHuman(string $app): array
    {
        try {
            return $this->removeRule($app);
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
