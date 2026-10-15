<?php

declare(strict_types=1);

namespace Orbit\Extensions\Documents\Infrastructure;

use App\Domain\Activity\ActivityInputRedaction;
use Closure;

/** Keeps document bytes out of Activity ([Activity](/cli/activity)). */
final readonly class DocumentsActivityInputRedaction implements ActivityInputRedaction
{
    public function commands(): array
    {
        return [
            'project:document:list',
            'project:document:search',
            'project:document:create',
            'project:document:show',
            'project:document:update',
            'project:document:write',
            'project:document:read',
            'project:document:download',
            'project:document:version:list',
            'project:document:restore-version',
            'project:document:archive',
            'project:document:restore',
            'project:document:destroy',
        ];
    }

    public function input(string $command, Closure $input): array
    {
        // Body parsing belongs after Node access, and document bytes never enter Activity.
        return [];
    }
}
