<?php

declare(strict_types=1);

namespace App\Commands\Cloudflare;

use App\Commands\Concerns\WithStepTree;
use App\Exceptions\GatewayApiException;
use RuntimeException;

final class CfSslDisableCommand extends CloudflareGatewayCommand
{
    use WithStepTree;

    #[\Override]
    protected $signature = 'cf-ssl:disable
        {zone? : Cloudflare zone ID or domain}
        {--force : Confirm destructive operation without prompting}
        {--json : Output JSON}';

    #[\Override]
    protected $description = 'Disable Cloudflare SSL for a zone through the gateway.';

    public function handle(): int
    {
        if (($failure = $this->guardLocalExtension()) !== null) {
            return $failure;
        }

        $zone = $this->requiredArgument('zone', 'zone', 'A Cloudflare zone is required.');

        if (is_int($zone)) {
            return $zone;
        }

        $consent = $this->confirmDestructive('Disabling Cloudflare SSL requires --force in non-interactive mode.');

        if (is_int($consent)) {
            return $consent;
        }

        if ($this->wantsJson()) {
            try {
                $response = $this->disable($zone);
            } catch (GatewayApiException $exception) {
                return $this->renderGatewayFailure($exception);
            }

            return $this->renderSuccess($response);
        }

        return $this->renderDisableTree($zone);
    }

    private function renderDisableTree(string $zone): int
    {
        $response = [];

        $outcome = $this->runStepOperation(
            'Disabling Cloudflare SSL',
            [
                ['label' => 'Resolve Cloudflare zone'],
                ['label' => 'Confirm destructive SSL change'],
                ['label' => 'Update Cloudflare SSL mode'],
            ],
            work: function () use ($zone, &$response): array {
                return $response = $this->disableForHuman($zone);
            },
            doneFooter: function () use ($zone, &$response): string {
                $resolvedZone = $this->successData($response)['zone'] ?? null;
                $displayZone = is_string($resolvedZone) && $resolvedZone !== '' ? $resolvedZone : $zone;

                return "Cloudflare SSL disabled for {$displayZone}";
            },
        );

        return $outcome->isCompleted() ? self::SUCCESS : self::FAILURE;
    }

    /**
     * @return array<string, mixed>
     */
    private function disable(string $zone): array
    {
        return $this->gatewayPut('/api/cloudflare/zones/'.rawurlencode($zone).'/ssl/disable', [
            'destructive_consent' => true,
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function disableForHuman(string $zone): array
    {
        try {
            return $this->disable($zone);
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
