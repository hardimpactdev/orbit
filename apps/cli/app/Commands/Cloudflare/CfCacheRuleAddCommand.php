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
        {project? : Orbit project name}
        {--json : Output JSON}';

    #[\Override]
    protected $description = 'Add or converge a Cloudflare cache rule for a project through the gateway.';

    public function handle(): int
    {
        if (($failure = $this->guardLocalExtension()) !== null) {
            return $failure;
        }

        $project = $this->requiredArgument('project', 'project', 'A project name is required.');

        if (is_int($project)) {
            return $project;
        }

        if ($this->wantsJson()) {
            try {
                $response = $this->addRule($project);
            } catch (GatewayApiException $exception) {
                return $this->renderGatewayFailure($exception);
            }

            return $this->renderSuccess($response);
        }

        return $this->renderAddTree($project);
    }

    private function renderAddTree(string $project): int
    {
        $response = [];

        $outcome = $this->runStepOperation(
            'Adding Cloudflare cache rule',
            [
                ['label' => 'Resolve project domain'],
                ['label' => 'Resolve Cloudflare zone'],
                ['label' => 'Write cache rule'],
            ],
            work: function () use ($project, &$response): array {
                return $response = $this->addRuleForHuman($project);
            },
            doneFooter: function () use ($project, &$response): string {
                $resolvedProject = $this->successData($response)['project'] ?? null;
                $displayProject = is_string($resolvedProject) && $resolvedProject !== ''
                    ? $resolvedProject
                    : $project;

                return "Cloudflare cache rule ready for {$displayProject}: respect origin Cache-Control";
            },
        );

        return $outcome->isCompleted() ? self::SUCCESS : self::FAILURE;
    }

    /**
     * @return array<string, mixed>
     */
    private function addRule(string $project): array
    {
        return $this->gatewayPost('/api/cloudflare/cache-rules/'.rawurlencode($project));
    }

    /**
     * @return array<string, mixed>
     */
    private function addRuleForHuman(string $project): array
    {
        try {
            return $this->addRule($project);
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
