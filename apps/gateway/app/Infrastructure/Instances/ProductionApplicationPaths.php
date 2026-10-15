<?php

declare(strict_types=1);

namespace App\Infrastructure\Instances;

use App\Domain\SourceControl\ApplicationDirectory;

final class ProductionApplicationPaths
{
    /** @param list<string>|null $sharedPaths */
    public static function render(string $program, ?string $root, ?array $sharedPaths = null): string
    {
        $suffix = $root === 'public' || (is_string($root) && str_ends_with($root, '/public'))
            ? ApplicationDirectory::resolve('', $root)
            : '';
        $target = str_repeat('../', 2 + substr_count($suffix, '/')).'.env';

        $assignment = $suffix === '' ? '' : 'application_suffix='.escapeshellarg($suffix)."\n";

        $program = str_replace('__SHARED_PATHS__', $sharedPaths === null ? '' : self::sharedPaths($sharedPaths), $program);

        return $assignment.strtr($program, [
            '__APPLICATION_SUFFIX__' => $suffix === '' ? '' : '${application_suffix}',
            '__ENVIRONMENT_TARGET__' => $target,
            '__ENVIRONMENT_PATH__' => $suffix === '' ? '.env' : '"${application_suffix#/}/.env"',
        ]);
    }

    /** @param list<string> $paths */
    private static function sharedPaths(array $paths): string
    {
        $entries = base64_encode(implode("\n", $paths));

        return 'shared_paths='.escapeshellarg($entries)."\n".<<<'BASH'
            while IFS= read -r path; do
                [ -n "$path" ] || continue
                case "$relative_root" in "$path"|"$path"/*) exit 1 ;; esac
                source="$release/$path"
                target="$home/$path"
                # Check before mkdir and before removal: neither side may traverse a link.
                test "$(sudo -u "$user" -H realpath -m -- "$source")" = "$source"
                test "$(sudo -u "$user" -H realpath -m -- "$target")" = "$target"
                sudo -u "$user" -H test ! -L "$source"
                sudo -u "$user" -H test ! -L "$target"
                if sudo -u "$user" -H test -e "$source"; then
                    sudo -u "$user" -H test -d "$source"
                fi
                sudo -u "$user" -H mkdir -p -- "$target"
                test "$(sudo -u "$user" -H realpath -e -- "$target")" = "$target"
                entry=$(sudo -u "$user" -H find -P "$target" -mindepth 1 -maxdepth 1 -print -quit)
                if [ -z "$entry" ] && sudo -u "$user" -H test -d "$source"; then
                    # Publish a complete seed at once. A failed copy leaves the empty target for a retry.
                    (
                        seed=$(sudo -u "$user" -H mktemp -d "${target}.seed.XXXXXXXX")
                        trap 'sudo -u "$user" -H rm -rf -- "$seed"' EXIT
                        nested=$(sudo -u "$user" -H find -P "$source" -type l -print -quit)
                        test -z "$nested"
                        sudo -u "$user" -H cp -a -- "$source/." "$seed/"
                        sudo -u "$user" -H mv -T -- "$seed" "$target"
                    )
                fi
                sudo -u "$user" -H mkdir -p -- "$(dirname -- "$source")"
                sudo -u "$user" -H rm -rf -- "$source"
                sudo -u "$user" -H ln -s -- "$target" "$source"
            done < <(printf '%s' "$shared_paths" | base64 --decode; printf '\n')
            application="$release__APPLICATION_SUFFIX__"
            storage="$application/storage"
            if sudo -u "$user" -H test -L "$storage" && sudo -u "$user" -H test -d "$application/public"; then
                relative=${application#"$release"}
                target="$home$relative/storage/app/public"
                test "$(sudo -u "$user" -H realpath -m -- "$storage/app/public")" = "$target"
                sudo -u "$user" -H mkdir -p -- "$target"
                if ! sudo -u "$user" -H test -e "$application/public/storage" && ! sudo -u "$user" -H test -L "$application/public/storage"; then
                    sudo -u "$user" -H ln -s -- ../storage/app/public "$application/public/storage"
                fi
            fi
            BASH;
    }
}
