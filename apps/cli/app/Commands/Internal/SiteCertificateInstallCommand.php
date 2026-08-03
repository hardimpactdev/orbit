<?php

declare(strict_types=1);

namespace App\Commands\Internal;

use App\Services\Certificates\LocalSiteCertificateInstallAction;
use App\Services\Certificates\LocalSiteCertificateInstallFailure;
use InvalidArgumentException;
use JsonException;

final class SiteCertificateInstallCommand extends InternalExecutorCommand
{
    #[\Override]
    protected $signature = 'internal:site-certificate:install {--operation-token=} {--json}';

    #[\Override]
    protected $description = 'Install local site certificate material through fixed argv operations';

    public function handle(LocalSiteCertificateInstallAction $action): int
    {
        if (! $this->verifyOperationToken('internal:site-certificate:install')) {
            return self::FAILURE;
        }

        try {
            return $this->emitInternalSuccess($action->install($this->readPayload()));
        } catch (LocalSiteCertificateInstallFailure $failure) {
            return $this->renderFailure($failure->errorCode, $failure->getMessage(), $failure->meta);
        } catch (InvalidArgumentException|JsonException) {
            return $this->renderFailure('validation_failed', 'Site certificate payload is invalid.', []);
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function readPayload(): array
    {
        $stdin = $this->stdin();

        if ($stdin === '') {
            throw new InvalidArgumentException('Site certificate payload must be provided on stdin.');
        }

        /** @var mixed $payload */
        $payload = json_decode($stdin, associative: true, flags: JSON_THROW_ON_ERROR);

        if (! is_array($payload)) {
            throw new InvalidArgumentException('Site certificate payload must be an object.');
        }

        foreach (array_keys($payload) as $key) {
            if (! is_string($key)) {
                throw new InvalidArgumentException('Site certificate payload keys must be strings.');
            }
        }

        /** @var array<string, mixed> $payload */
        return $payload;
    }
}
