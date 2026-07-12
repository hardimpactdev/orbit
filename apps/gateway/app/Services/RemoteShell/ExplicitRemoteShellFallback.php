<?php

declare(strict_types=1);

namespace App\Services\RemoteShell;

final readonly class ExplicitRemoteShellFallback
{
    // @orbit-ssh-lane transitional-ssh
    public const string HEADER = 'X-Orbit-Node-Transport-Preference';

    public const string REQUIRED = 'transitional-ssh-fallback';

    public function allowed(): bool
    {
        return request()->header(self::HEADER) === self::REQUIRED;
    }

    public function message(string $surface): string
    {
        return "{$surface} still uses RemoteShell and requires explicit --node-transport=transitional-ssh-fallback until it is migrated to agent-push.";
    }

    /**
     * @return array{field: string, required: string}
     */
    public function meta(): array
    {
        return [
            'field' => 'node-transport',
            'required' => self::REQUIRED,
        ];
    }
}
