<?php

declare(strict_types=1);

namespace App\Tools;

final class OrbStackTool extends BaseTool
{
    public function slug(): string
    {
        return 'orbstack';
    }

    #[\Override]
    public function category(): string
    {
        return 'infrastructure';
    }

    #[\Override]
    public function supportedOperatingSystems(): array
    {
        return ['macos'];
    }

    #[\Override]
    public function capabilities(): array
    {
        return ['install', 'update', 'safe-adopt', 'start', 'stop', 'restart'];
    }

    #[\Override]
    public function installScript(array $config = []): string
    {
        return <<<'BASH'
            #!/usr/bin/env bash
            # orbit install orbstack
            set -e

            case "$(uname -s)" in
                Darwin) ;;
                *) echo "orbstack: unsupported os" >&2; exit 64 ;;
            esac

            if ! command -v brew >/dev/null 2>&1; then
                echo "orbstack: Homebrew is required on macOS" >&2
                exit 64
            fi

            brew install orbstack
            BASH;
    }

    #[\Override]
    public function updateScript(array $config = []): string
    {
        return 'brew upgrade --greedy orbstack';
    }

    #[\Override]
    public function probeMetadata(): array
    {
        return [
            'binary' => 'orb',
            'version_command' => 'orb version 2>/dev/null | head -n 1',
            'probe' => 'command -v orbctl >/dev/null 2>&1 && test -d /Applications/OrbStack.app',
            'provider_command' => 'orbctl status',
            'update_command' => $this->updateScript(),
        ];
    }

    #[\Override]
    protected function lifecycleScript(string $action, array $config): string
    {
        return <<<BASH
            #!/usr/bin/env bash
            # orbit {$action} orbstack
            set -e

            case "\$(uname -s)" in
                Darwin) ;;
                *) echo "orbstack: unsupported os" >&2; exit 64 ;;
            esac

            if ! command -v orbctl >/dev/null 2>&1; then
                echo "orbstack: orbctl is required" >&2
                exit 64
            fi

            orbctl {$action}
            BASH;
    }
}
