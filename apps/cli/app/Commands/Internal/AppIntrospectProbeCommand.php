<?php

declare(strict_types=1);

namespace App\Commands\Internal;

use App\Services\Apps\LocalAppIntrospectProbe;
use App\Services\Apps\LocalAppIntrospectProbeFailure;
use InvalidArgumentException;
use JsonException;

final class AppIntrospectProbeCommand extends InternalExecutorCommand
{
    #[\Override]
    protected $signature = 'internal:app-introspect:probe {--operation-token=} {--json}';

    #[\Override]
    protected $description = 'Probe one app reality snapshot on the local node';

    public function handle(LocalAppIntrospectProbe $probe): int
    {
        if (! $this->verifyOperationToken('internal:app-introspect:probe')) {
            return self::FAILURE;
        }

        try {
            $result = $probe->probe($this->readPayload());
        } catch (LocalAppIntrospectProbeFailure $failure) {
            return $this->renderFailure($failure->errorCode, $failure->getMessage(), $failure->meta);
        } catch (InvalidArgumentException|JsonException) {
            return $this->renderFailure('validation_failed', 'App introspection payload is invalid.', []);
        }

        return $this->emitInternalSuccess($result['data'], $result['meta']);
    }

    /**
     * @return array<string, mixed>
     */
    private function readPayload(): array
    {
        $stdin = $this->stdin();

        if ($stdin === '') {
            throw new InvalidArgumentException('App introspection payload must be provided on stdin.');
        }

        /** @var mixed $payload */
        $payload = json_decode($stdin, associative: true, flags: JSON_THROW_ON_ERROR);

        if (! is_array($payload)) {
            throw new InvalidArgumentException('App introspection payload must be an object.');
        }

        foreach (array_keys($payload) as $key) {
            if (! is_string($key)) {
                throw new InvalidArgumentException('App introspection payload must be an object.');
            }
        }

        /** @var array<string, mixed> $payload */
        return $payload;
    }
}
