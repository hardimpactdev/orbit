<?php

declare(strict_types=1);

namespace App\Commands\Internal;

use App\Services\Operations\LocalFleetUpdateInstallCliAction;
use App\Services\Operations\LocalFleetUpdateInstallCliFailure;
use InvalidArgumentException;
use JsonException;

final class FleetUpdateInstallCliCommand extends InternalExecutorCommand
{
    #[\Override]
    protected $signature = 'internal:fleet-update:install-cli
        {--payload-file= : Read the install payload from this local file}
        {--payload-sha256= : Expected SHA-256 for the payload file}
        {--operation-token=}
        {--json}';

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
        $payloadFile = $this->option('payload-file');

        if (is_string($payloadFile) && trim($payloadFile) !== '') {
            return $this->decodePayload($this->payloadFileContents($payloadFile));
        }

        $stdin = $this->stdin();

        if ($stdin === '') {
            throw new InvalidArgumentException('Fleet update CLI install payload must be provided on stdin.');
        }

        return $this->decodePayload($stdin);
    }

    /**
     * @return array<string, mixed>
     */
    private function decodePayload(string $payload): array
    {
        /** @var mixed $decoded */
        $decoded = json_decode($payload, associative: true, flags: JSON_THROW_ON_ERROR);

        if (! is_array($decoded)) {
            throw new InvalidArgumentException('Fleet update CLI install payload must be an object.');
        }

        /** @var array<string, mixed> $decoded */
        return $decoded;
    }

    private function payloadFileContents(string $path): string
    {
        $expectedSha256 = $this->option('payload-sha256');

        if (! is_string($expectedSha256) || preg_match('/\A[a-fA-F0-9]{64}\z/', $expectedSha256) !== 1) {
            throw new InvalidArgumentException('Fleet update CLI install payload hash is invalid.');
        }

        if (! is_file($path)) {
            throw new InvalidArgumentException('Fleet update CLI install payload file does not exist.');
        }

        $contents = file_get_contents($path);

        if (! is_string($contents)) {
            throw new InvalidArgumentException('Fleet update CLI install payload file could not be read.');
        }

        if (! hash_equals(strtolower($expectedSha256), hash('sha256', $contents))) {
            throw new InvalidArgumentException('Fleet update CLI install payload hash mismatch.');
        }

        return $contents;
    }
}
