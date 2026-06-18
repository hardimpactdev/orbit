<?php

declare(strict_types=1);

namespace App\Commands\Cloudflare;

use App\Commands\Concerns\WithStepTree;
use App\Exceptions\GatewayApiException;
use RuntimeException;

final class CfCacheFlushCommand extends CloudflareGatewayCommand
{
    use WithStepTree;

    #[\Override]
    protected $signature = 'cf-cache:flush
        {--zone= : Cloudflare zone ID, domain, or app name}
        {--json : Output JSON}';

    #[\Override]
    protected $description = 'Flush Cloudflare cache for a zone through the gateway.';

    public function handle(): int
    {
        $zone = $this->stringOption('zone');

        if ($zone === null && ! $this->wantsJson() && $this->input->isInteractive()) {
            $answer = $this->ask('Cloudflare zone');
            $zone = is_string($answer) && trim($answer) !== '' ? trim($answer) : null;
        }

        if ($zone === null) {
            return $this->failValidation('zone', 'A Cloudflare zone is required.');
        }

        if ($this->wantsJson()) {
            try {
                $response = $this->flush($zone);
            } catch (GatewayApiException $exception) {
                return $this->renderGatewayFailure($exception);
            }

            return $this->renderSuccess($response);
        }

        return $this->renderFlushTree($zone);
    }

    private function renderFlushTree(string $zone): int
    {
        $response = [];

        $outcome = $this->runStepOperation(
            'Flushing Cloudflare cache',
            [
                ['label' => 'Resolve Cloudflare zone'],
                ['label' => 'Request cache purge'],
            ],
            work: function () use ($zone, &$response): array {
                return $response = $this->flushForHuman($zone);
            },
            doneFooter: function () use ($zone, &$response): string {
                $resolvedZone = $this->successData($response)['zone'] ?? null;
                $displayZone = is_string($resolvedZone) && $resolvedZone !== '' ? $resolvedZone : $zone;

                return "Cloudflare cache flushed for {$displayZone}";
            },
        );

        return $outcome->isCompleted() ? self::SUCCESS : self::FAILURE;
    }

    /**
     * @return array<string, mixed>
     */
    private function flush(string $zone): array
    {
        return $this->gatewayPost('/api/cloudflare/cache/flush', ['zone' => $zone]);
    }

    /**
     * @return array<string, mixed>
     */
    private function flushForHuman(string $zone): array
    {
        try {
            return $this->flush($zone);
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
        $cache = $response['success']['data']['cache'] ?? null;

        return is_array($cache) ? $cache : [];
    }
}
