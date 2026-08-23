<?php

declare(strict_types=1);

namespace App\Services\Operations;

use App\Services\Updates\PendingDesktopUpdateHandoff;

/** @mago-expect lint:mixed-assignment */
final readonly class LocalFleetUpdateInstallPendingDesktopUpdatePayload
{
    public function __construct(
        public string $path,
        public string $operationId,
        public string $version,
        public ?string $buildId,
        public string $installMode,
    ) {}

    public static function fromPayload(mixed $payload): ?self
    {
        if ($payload === null) {
            return null;
        }

        if (! is_array($payload)) {
            throw LocalFleetUpdateInstallCliPayloadField::validationFailure('pending_desktop_update');
        }

        $installMode = LocalFleetUpdateInstallCliPayloadField::nonEmptyString(
            $payload['install_mode'] ?? null,
            'pending_desktop_update.install_mode',
        );

        if (! in_array(
            $installMode,
            [PendingDesktopUpdateHandoff::InstallModeRestartReady, PendingDesktopUpdateHandoff::InstallModeAutomatic],
            true,
        )) {
            throw LocalFleetUpdateInstallCliPayloadField::validationFailure('pending_desktop_update.install_mode');
        }

        $buildId = $payload['build_id'] ?? null;

        if ($buildId !== null && (! is_string($buildId) || trim($buildId) === '')) {
            throw LocalFleetUpdateInstallCliPayloadField::validationFailure('pending_desktop_update.build_id');
        }

        return new self(
            path: LocalFleetUpdateInstallCliPayloadField::absolutePath(
                $payload['path'] ?? null,
                'pending_desktop_update.path',
            ),
            operationId: LocalFleetUpdateInstallCliPayloadField::nonEmptyString(
                $payload['operation_id'] ?? null,
                'pending_desktop_update.operation_id',
            ),
            version: LocalFleetUpdateInstallCliPayloadField::nonEmptyString(
                $payload['version'] ?? null,
                'pending_desktop_update.version',
            ),
            buildId: is_string($buildId) ? $buildId : null,
            installMode: $installMode,
        );
    }
}
