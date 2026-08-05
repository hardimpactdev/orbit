<?php

declare(strict_types=1);

namespace App\Commands\Concerns;

use App\Exceptions\GatewayApiException;
use App\Services\ApplicationLogs\ApplicationLogFlags;
use App\Services\ApplicationLogs\ApplicationLogFollowClient;
use App\Services\ApplicationLogs\ApplicationLogUrlParser;

/**
 * Shared application-log CLI helpers used by GatewayCommand subclasses.
 *
 * @phpstan-require-extends \App\Commands\GatewayCommand
 */
trait ReadsApplicationLogs
{
    protected function parseApplicationLogFlags(): ApplicationLogFlags|int
    {
        $parsed = ApplicationLogFlags::fromOptions(
            lines: $this->option('lines'),
            follow: $this->option('follow'),
            json: $this->wantsJson(),
            node: $this->option('node'),
        );

        if ($parsed['ok'] === false) {
            return $this->renderFailure('validation_failed', $parsed['message'], [
                'field' => $parsed['field'],
            ]);
        }

        return $parsed['flags'];
    }

    /**
     * @return array{ok: true, host: string}|int
     */
    protected function parseApplicationLogHost(string $value): array|int
    {
        $parsed = app(ApplicationLogUrlParser::class)->parse($value);

        if ($parsed['ok'] === false) {
            return $this->renderFailure('validation_failed', $parsed['message'], [
                'field' => $parsed['field'],
            ]);
        }

        return $parsed;
    }

    /**
     * @param  array<string, mixed>  $query
     * @param  array<string, string>  $headers
     */
    protected function followApplicationLog(string $streamPath, array $query, array $headers = []): int
    {
        try {
            $response = $this->gatewayPost($streamPath, $query, $headers);
            app(ApplicationLogFollowClient::class)->consumeStartedStream(
                $response,
                function (string $data): void {
                    $this->output->write($data);
                },
            );

            return self::SUCCESS;
        } catch (GatewayApiException $exception) {
            return $this->renderGatewayFailure($exception);
        }
    }

    /**
     * @param  array<string, mixed>  $response
     */
    protected function renderApplicationLogLines(array $response): int
    {
        $data = $this->applicationLogSuccessData($response);
        $lines = is_array($data['lines'] ?? null) ? $data['lines'] : [];

        foreach ($lines as $line) {
            if (is_string($line)) {
                $this->line($line);
            }
        }

        return self::SUCCESS;
    }

    /**
     * @param  array<string, mixed>  $response
     * @return array<string, mixed>
     */
    protected function applicationLogSuccessData(array $response): array
    {
        $success = $response['success'] ?? null;

        if (is_array($success) && is_array($success['data'] ?? null)) {
            /** @var array<string, mixed> $data */
            $data = $success['data'];

            return $data;
        }

        return $response;
    }
}
