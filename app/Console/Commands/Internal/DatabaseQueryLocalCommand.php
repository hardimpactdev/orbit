<?php

declare(strict_types=1);

namespace App\Console\Commands\Internal;

use App\Services\DatabaseConnections\DatabaseQueryRunner;
use App\Services\DatabaseConnections\DatabaseQueryRunnerFailure;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use JsonException;
use Symfony\Component\Console\Input\StreamableInputInterface;
use Throwable;

#[Signature('database:query-local')]
#[Description('Execute a database query from a stdin payload')]
final class DatabaseQueryLocalCommand extends Command
{
    protected $hidden = true;

    public function handle(DatabaseQueryRunner $runner): int
    {
        try {
            $payload = $this->readPayload();
            $result = $runner->run(
                connection: $payload['connection'],
                sql: $this->stringValue($payload, 'sql'),
                options: [
                    'write' => (bool) ($payload['write'] ?? false),
                    'full' => (bool) ($payload['full'] ?? false),
                    'limit' => $payload['limit'] ?? null,
                    'timeout' => $payload['timeout'] ?? null,
                    'max_json_bytes' => $payload['max_json_bytes'] ?? null,
                ],
            );

            return $this->jsonSuccess($result['data'], $result['meta']);
        } catch (DatabaseQueryRunnerFailure $failure) {
            return $this->jsonError($failure->errorCode, $failure->getMessage(), $failure->meta);
        } catch (Throwable) {
            return $this->jsonError('validation_failed', 'Database query payload is invalid.', []);
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function readPayload(): array
    {
        $stdin = $this->stdin();

        if ($stdin === '') {
            throw new \InvalidArgumentException('Connection payload must be provided on stdin.');
        }

        try {
            $payload = json_decode($stdin, true, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new \InvalidArgumentException('Connection payload must be valid JSON.', previous: $exception);
        }

        if (! is_array($payload) || ! is_array($payload['connection'] ?? null)) {
            throw new \InvalidArgumentException('Connection payload is required.');
        }

        return $payload;
    }

    private function stdin(): string
    {
        $stream = $this->input instanceof StreamableInputInterface ? $this->input->getStream() : null;

        if (is_resource($stream)) {
            return (string) stream_get_contents($stream);
        }

        return (string) stream_get_contents(STDIN);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function stringValue(array $payload, string $key): string
    {
        $value = $payload[$key] ?? null;

        if (! is_string($value) || trim($value) === '') {
            throw new \InvalidArgumentException('SQL is required.');
        }

        return $value;
    }

    /**
     * @param  array<string, mixed>  $data
     * @param  array<string, mixed>  $meta
     */
    private function jsonSuccess(array $data, array $meta): int
    {
        $this->line(json_encode([
            'success' => [
                'data' => $data,
                'meta' => $meta === [] ? (object) [] : $meta,
            ],
        ], JSON_THROW_ON_ERROR));

        return self::SUCCESS;
    }

    /**
     * @param  array<string, mixed>  $meta
     */
    private function jsonError(string $code, string $message, array $meta): int
    {
        $this->line(json_encode([
            'error' => [
                'code' => $code,
                'message' => $message,
                'meta' => $meta === [] ? (object) [] : $meta,
            ],
        ], JSON_THROW_ON_ERROR));

        return self::FAILURE;
    }
}
