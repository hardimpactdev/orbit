<?php

declare(strict_types=1);

namespace Orbit\Extensions\AiRouter\Infrastructure;

use App\Infrastructure\Processes\ProtectedInput;
use App\Infrastructure\Ssh\RemoteCommand;

/**
 * Installs and removes the Orbit collector script on the Process Node. The install prints
 * `orbit-ai-router-source=unchanged` when the live script already matches, and
 * `orbit-ai-router-source=published` after it replaced it.
 */
final readonly class AiRouterSourcePublisher
{
    public const string Published = 'orbit-ai-router-source=published';

    public const string Unchanged = 'orbit-ai-router-source=unchanged';

    public function command(string $script): RemoteCommand
    {
        $encoded = base64_encode($script);
        $directory = AiRouterFootprint::SourceDirectory;
        $path = AiRouterFootprint::SourcePath;

        $published = self::Published;
        $unchanged = self::Unchanged;
        $body = <<<BASH
            directory={$directory}
            path={$path}
            install -d -o root -g root -m 0755 -- "\$directory"
            candidate="\$path.orbit-candidate"
            trap 'rm -f -- "\$candidate"' EXIT
            printf '%s' '{$encoded}' | base64 --decode > "\$candidate"
            chmod 0644 "\$candidate"
            if [ -f "\$path" ] && cmp -s -- "\$candidate" "\$path"; then
                rm -f -- "\$candidate"
                echo {$unchanged}
                exit 0
            fi
            mv -fT -- "\$candidate" "\$path"
            echo {$published}
            BASH;

        return new RemoteCommand(
            arguments: ['sudo', 'bash', '-seu'],
            protectedInput: ProtectedInput::fromString($body),
        );
    }

    public function inspectCommand(): RemoteCommand
    {
        return new RemoteCommand(arguments: ['sudo', 'sha256sum', '--', AiRouterFootprint::SourcePath]);
    }

    public function removeCommand(string $directory = AiRouterFootprint::SourceDirectory): RemoteCommand
    {
        return new RemoteCommand(
            arguments: ['sudo', 'rm', '-rf', '--', $directory],
        );
    }
}
