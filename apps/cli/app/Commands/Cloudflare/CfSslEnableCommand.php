<?php

declare(strict_types=1);

namespace App\Commands\Cloudflare;

use App\Commands\Concerns\WithStepTree;
use App\Exceptions\GatewayApiException;
use RuntimeException;

final class CfSslEnableCommand extends CloudflareGatewayCommand
{
    use WithStepTree;

    #[\Override]
    protected $signature = 'cf-ssl:enable
        {zone? : Cloudflare zone ID or domain}
        {--mode=strict : SSL mode, strict or full}
        {--json : Output JSON}';

    #[\Override]
    protected $description = 'Enable Cloudflare SSL mode for a zone through the gateway.';

    public function handle(): int
    {
        $zone = $this->requiredArgument('zone', 'zone', 'A Cloudflare zone is required.');

        if (is_int($zone)) {
            return $zone;
        }

        if ($this->wantsJson()) {
            try {
                $response = $this->enable($zone);
            } catch (GatewayApiException $exception) {
                return $this->renderGatewayFailure($exception);
            }

            return $this->renderSuccess($response);
        }

        return $this->renderEnableTree($zone);
    }

    private function renderEnableTree(string $zone): int
    {
        $response = [];

        $outcome = $this->runStepOperation(
            'Enabling Cloudflare SSL',
            [
                ['label' => 'Resolve Cloudflare zone'],
                ['label' => 'Validate SSL mode'],
                ['label' => 'Update Cloudflare SSL mode'],
            ],
            work: function () use ($zone, &$response): array {
                return $response = $this->enableForHuman($zone);
            },
            doneFooter: function () use ($zone, &$response): string {
                $ssl = $this->successData($response);
                $resolvedZone = $ssl['zone'] ?? null;
                $displayZone = is_string($resolvedZone) && $resolvedZone !== '' ? $resolvedZone : $zone;
                $resolvedMode = $ssl['mode'] ?? null;
                $displayMode = is_string($resolvedMode) && $resolvedMode !== '' ? $resolvedMode : ($this->stringOption('mode') ?? 'strict');

                return "Cloudflare SSL mode for {$displayZone} set to {$displayMode}";
            },
        );

        return $outcome->isCompleted() ? self::SUCCESS : self::FAILURE;
    }

    /**
     * @return array<string, mixed>
     */
    private function enable(string $zone): array
    {
        return $this->gatewayPut('/api/cloudflare/zones/'.rawurlencode($zone).'/ssl', [
            'mode' => $this->stringOption('mode') ?? 'strict',
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function enableForHuman(string $zone): array
    {
        try {
            return $this->enable($zone);
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
        $ssl = $response['success']['data']['ssl'] ?? null;

        return is_array($ssl) ? $ssl : [];
    }
}
