<?php

declare(strict_types=1);

namespace App\Tools;

final class CursorCliTool extends UserScopedCliTool
{
    public function slug(): string
    {
        return 'cursor-cli';
    }

    #[\Override]
    public function cliProfile(): UserScopedCliProfile
    {
        return new UserScopedCliProfile(
            binaryName: 'cursor-agent',
            installCommand: static fn (string $_version): string => 'curl https://cursor.com/install -fsS | bash',
            versionCommand: static function (string $binary): string {
                $escapedBinary = escapeshellarg($binary);

                return str_replace('__BINARY__', $escapedBinary, <<<'BASH'
                    test -x __BINARY__
                    target="$(readlink __BINARY__ 2>/dev/null || true)"
                    test -n "$target"
                    case "$target" in
                        *"/versions/"*)
                            version="${target#*/versions/}"
                            printf "%s\n" "${version%%/*}"
                            ;;
                        *)
                            printf "installed\n"
                            ;;
                    esac
                    BASH);
            },
        );
    }
}
