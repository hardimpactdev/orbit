<?php

declare(strict_types=1);

namespace App\Commands;

use App\Exceptions\GatewayApiException;

final class GatewayStatusCommand extends OrbitCommand
{
    private const string ENDPOINT = '/api/status';

    #[\Override]
    protected $signature = 'gateway:status {--json}';

    #[\Override]
    protected $description = 'Show gateway API status';

    public function handle(): int
    {
        try {
            $response = $this->gateway()->get(self::ENDPOINT);
        } catch (GatewayApiException $exception) {
            return $this->renderFailure(
                $exception->cliFailureCode(),
                $exception->getMessage(),
                $this->failureMeta($exception),
            );
        }

        if ($this->wantsJson()) {
            return $this->renderSuccess($response, ['endpoint' => self::ENDPOINT]);
        }

        return $this->renderSuccess([
            'gateway' => $response['gateway'] ?? $response,
        ], ['endpoint' => self::ENDPOINT]);
    }

    /**
     * @return array<string, mixed>
     */
    private function failureMeta(GatewayApiException $exception): array
    {
        $meta = ['endpoint' => self::ENDPOINT];

        if ($exception->statusCode() !== null) {
            $meta['status_code'] = $exception->statusCode();
        }

        if ($exception->bodyExcerpt() !== null) {
            $meta['body_excerpt'] = $exception->bodyExcerpt();
        }

        return $meta;
    }
}
