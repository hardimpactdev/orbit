<?php

declare(strict_types=1);

namespace App\Commands\Internal;

use App\Services\Operations\LocalFleetUpdateInstallCliAction;
use App\Services\Operations\LocalFleetUpdateInstallCliFailure;
use InvalidArgumentException;
use JsonException;
use Symfony\Component\Console\Input\StreamableInputInterface;

final class FleetUpdateInstallCliCommand extends InternalExecutorCommand
{
    #[\Override]
    protected $signature = 'internal:fleet-update:install-cli {--operation-token=} {--json}';

    #[\Override]
    protected $description = 'Install the local Orbit CLI artifact for a fleet update';

    public function handle(LocalFleetUpdateInstallCliAction $installer): int
    {
        if (! $this->verifyOperationToken('internal:fleet-update:install-cli')) {
            return self::FAILURE;
        }

        try {
            $result = $installer->run($this->payload());
        } catch (LocalFleetUpdateInstallCliFailure $failure) {
            return $this->renderFailure($failure->errorCode, $failure->getMessage(), $failure->meta);
        } catch (InvalidArgumentException|JsonException) {
            return $this->renderFailure('validation_failed', 'Fleet update CLI install payload is invalid.', []);
        }

        return $this->emitInternalSuccess($result);
    }

    /**
     * @return array<string, mixed>
     */
    private function payload(): array
    {
        $stdin = $this->stdin();

        if ($stdin === '') {
            throw new InvalidArgumentException('Fleet update CLI install payload must be provided on stdin.');
        }

        /** @var mixed $decoded */
        $decoded = json_decode($stdin, associative: true, flags: JSON_THROW_ON_ERROR);

        if (! is_array($decoded)) {
            throw new InvalidArgumentException('Fleet update CLI install payload must be an object.');
        }

        /** @var array<string, mixed> $decoded */
        return $decoded;
    }

    private function stdin(): string
    {
        $stream = $this->input instanceof StreamableInputInterface ? $this->input->getStream() : null;

        if (is_resource($stream)) {
            return (string) stream_get_contents($stream);
        }

        return (string) stream_get_contents(STDIN);
    }
}
