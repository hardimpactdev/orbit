<?php

declare(strict_types=1);

namespace App\Infrastructure\Instances;

use App\Domain\Nodes\Storage\StoragePath;
use App\Domain\SourceControl\RelativeWebRoot;
use App\Infrastructure\AppDev\DevelopmentSite;
use App\Infrastructure\Ssh\RemoteCommand;
use Illuminate\Support\Collection;

final readonly class DevelopmentCaddyAccessCommand
{
    /**
     * The development sites whose checkout and Web root the command walks.
     *
     * @param  Collection<int, DevelopmentSite>  $sites
     * @return Collection<int, DevelopmentSite>
     */
    public function walkedSites(Collection $sites): Collection
    {
        return $sites
            ->reject(static fn (DevelopmentSite $site): bool => $site->isProxy() || $site->unavailable || $site->environment !== 'development' || $site->checkoutPath === '')
            ->values();
    }

    /**
     * Denies Caddy each checkout, then grants it each Web root, the Laravel public storage, and passage through the
     * directories above them. Every writer of a checkout is the managed user, who runs this walk, or the operator, so
     * plain ACL tools act by name. An entry that the managed user does not own fails the walk with "Operation not
     * permitted"; the walk never skips it. Root acts only on a directory that root owns, on a path that root owns from
     * `/` down, which is above every checkout and which no other account can replace. A failed walk restores what it
     * granted from a snapshot in a private directory under `/var/tmp`, and the deny on the rest stays.
     *
     * @param  Collection<int, DevelopmentSite>  $sites
     */
    public function command(Collection $sites): RemoteCommand
    {
        $arguments = ['bash', '-seu', '--'];
        foreach ($this->walkedSites($sites) as $site) {
            $arguments[] = StoragePath::parse($site->checkoutPath)->value;
            $arguments[] = RelativeWebRoot::validate($site->documentRoot);
            $arguments[] = $site->applicationDirectory();
        }

        return new RemoteCommand(
            arguments: $arguments,
            input: <<<'BASH'
                set -o pipefail
                me=$(id -un)
                # `root_owned <path>` succeeds when root owns the path and every directory above it. Only such a
                # directory is above every checkout, which the managed user owns, so only there may root act.
                root_owned() {
                    local path=$1
                    while :; do
                        test "$(stat -c %u -- "$path")" = 0 || return 1
                        if [ "$path" = / ]; then return 0; fi
                        path=$(dirname -- "$path")
                    done
                }
                # Setup can run while this walk does, for a task VM workspace whose Route follows its baseline
                # start: `vp build` rewrites the build output, and Composer republishes assets. An entry that
                # vanishes between a directory read and its own call needs no ACL, and an entry created in its
                # place inherits the default ACL of its directory. So a walk fails only for an error other than a
                # vanished entry. Each walk sets the default ACLs before the access ACLs, so a file created at any
                # point of the walk ends up with the right entry.
                walk() {
                    walk_errors=$(mktemp)
                    if "$@" 2> "$walk_errors"; then
                        rm -f -- "$walk_errors"
                        return 0
                    else
                        walk_status=$?
                    fi
                    if [ -s "$walk_errors" ] && ! grep -qv -e ': No such file or directory$' -- "$walk_errors"; then
                        rm -f -- "$walk_errors"
                        return 0
                    fi
                    cat -- "$walk_errors" >&2
                    rm -f -- "$walk_errors"
                    return "$walk_status"
                }
                checkouts=()
                roots=()
                applications=()
                storage=()
                git_directories=()
                while [ "$#" -gt 0 ]; do
                    checkout=$1
                    relative_root=$2
                    application=$3
                    shift 3
                    # A development default in the old release layout serves through `current` until it converts.
                    if [ -L "$checkout" ]; then
                        test "${checkout##*/}" = current
                        home=$(dirname -- "$checkout")
                        test -d "$home/.git" && test ! -L "$home/.git"
                        state="$home/.git/orbit-development-releases"
                        test -d "$state" && test ! -L "$state"
                        test -f "$state/identity" && test ! -L "$state/identity"
                        link=$(readlink -- "$checkout")
                        case "$link" in releases/*) ;; *) exit 1 ;; esac
                        name=${link#releases/}
                        printf '%s' "$name" | grep -Eq '^[A-Za-z0-9][A-Za-z0-9._-]{0,127}$'
                        test -f "$state/release-$name" && test ! -L "$state/release-$name"
                        test "$(cat -- "$state/release-$name")" = "$(cat -- "$state/identity"):$name"
                        resolved=$(realpath -e -- "$checkout")
                        test "$resolved" = "$home/releases/$name"
                        test "$(git -c core.hooksPath=/dev/null -c core.fsmonitor=false -C "$resolved" rev-parse --path-format=absolute --git-common-dir)" = "$home/.git"
                        application="$resolved${application#"$checkout"}"
                        checkout=$resolved
                    fi
                    test -d "$checkout"
                    test ! -L "$checkout"
                    test "$(realpath -e -- "$checkout")" = "$checkout"
                    test "$(git -c core.hooksPath=/dev/null -c core.fsmonitor=false -C "$checkout" rev-parse --show-toplevel)" = "$checkout"
                    test "$(stat -c %U -- "$checkout")" = "$me"
                    git_directory=$(git -c core.hooksPath=/dev/null -c core.fsmonitor=false -C "$checkout" rev-parse --path-format=absolute --git-common-dir)
                    test -d "$git_directory" && test ! -L "$git_directory"
                    test "$(realpath -e -- "$git_directory")" = "$git_directory"
                    test "$(stat -c %U -- "$git_directory")" = "$me"
                    git_directories+=("$git_directory")
                    document_root="$checkout/$relative_root"
                    test -d "$document_root"
                    test ! -L "$document_root"
                    test "$(realpath -e -- "$document_root")" = "$document_root"

                    storage_target=
                    links=$(walk find -P "$document_root" -type l -print0 | base64 -w0)
                    while IFS= read -r -d '' link; do
                        expected_target="$application/storage/app/public"
                        test "$document_root" = "$application/public"
                        test "$link" = "$document_root/storage"
                        test "$(realpath -e -- "$link")" = "$expected_target"
                        test -d "$expected_target"
                        test "$(realpath -e -- "$expected_target")" = "$expected_target"
                        nested=$(find -P "$expected_target" -type l -print -quit)
                        test -z "$nested"
                        storage_target=$expected_target
                    done < <(printf '%s' "$links" | base64 --decode)
                    checkouts+=("$checkout")
                    roots+=("$document_root")
                    applications+=("$application")
                    storage+=("$storage_target")
                done

                # The snapshot holds only what the walk grants. A failed walk restores those grants, and the deny on
                # the rest of each checkout stays, so the failure never widens access. The snapshots live in a private
                # directory under /var/tmp, on disk unlike a tmpfs /tmp, where Caddy cannot read them. A snapshot that
                # recovery could not apply stays there. `root` holds the root-owned directories above the checkouts
                # that root grants, and only root restores it. `managed` holds everything else. A new walk removes the
                # account's retained snapshots that are older than three days.
                find /var/tmp -mindepth 1 -maxdepth 1 -type d -name 'orbit-caddy-acl.*' -user "$me" -mtime +3 -exec rm -rf -- '{}' + 2> /dev/null || true
                snapshots=$(mktemp -d -p /var/tmp orbit-caddy-acl.XXXXXX)
                test "$(stat -c '%U %a' -- "$snapshots")" = "$me 700"
                saved=$snapshots/managed
                root_saved=$snapshots/root
                : > "$saved"
                : > "$root_saved"
                changed=0
                finish() {
                    result=$?
                    trap - EXIT
                    if [ "$result" != 0 ] && [ "$changed" = 1 ]; then
                        recovered=1
                        if [ -s "$saved" ]; then
                            (cd / && walk setfacl --restore="$saved") || recovered=0
                        fi
                        if [ -s "$root_saved" ]; then
                            (cd / && walk sudo -n setfacl --restore="$root_saved") || recovered=0
                        fi
                        if [ "$recovered" = 0 ]; then
                            printf 'Caddy access recovery failed; retained ACL snapshots: %s\n' "$snapshots" >&2
                            exit 1
                        fi
                    fi
                    rm -rf -- "$snapshots"
                    exit "$result"
                }
                trap finish EXIT
                # `caddy_can <r|x> <path>` succeeds when Caddy may read or search the path. The Bash builtin `test`
                # checks access with faccessat, so ACLs count; coreutils `test` reads only the mode bits.
                caddy_can() {
                    (cd / && sudo -n -u caddy bash -c 'test "-$1" "$2"' caddy-access "$1" "$2")
                }
                # `passage_grant <directory>` prints the ACL change that lets Caddy pass the directory: it adds
                # execute to Caddy's entry and to the mask, and nothing else.
                passage_grant() {
                    local current_acl caddy_acl mask
                    current_acl=$(getfacl -cp -- "$1")
                    caddy_acl=$(printf '%s\n' "$current_acl" | sed -n 's/^user:caddy:\([rwx-]\{3\}\).*$/\1/p')
                    caddy_acl=${caddy_acl:----}
                    mask=$(printf '%s\n' "$current_acl" | sed -n 's/^mask::\([rwx-]\{3\}\).*$/\1/p')
                    if [ -z "$mask" ]; then
                        mask=$(printf '%s\n' "$current_acl" | sed -n 's/^group::\([rwx-]\{3\}\).*$/\1/p')
                    fi
                    printf 'u:caddy:%sx,m::%sx' "${caddy_acl%?}" "${mask%?}"
                }
                # Root grants Caddy passage through a root-owned directory above the checkouts only when Caddy cannot
                # pass yet, and only such a directory that it saved first.
                root_grants=
                for index in "${!checkouts[@]}"; do
                    document_root=${roots[$index]}
                    walk getfacl -R -P -p -- "$document_root" >> "$saved"
                    ancestor=$document_root
                    while [ "$ancestor" != / ]; do
                        ancestor=$(dirname -- "$ancestor")
                        if ! root_owned "$ancestor"; then
                            getfacl -p -- "$ancestor" >> "$saved"
                        elif ! caddy_can x "$ancestor"; then
                            getfacl -p -- "$ancestor" >> "$root_saved"
                            root_grants+="$ancestor"$'\n'
                        fi
                    done
                    if [ -n "${storage[$index]}" ]; then
                        getfacl -p -- "${applications[$index]}/storage" "${applications[$index]}/storage/app" >> "$saved"
                        walk getfacl -R -P -p -- "${storage[$index]}" >> "$saved"
                    fi
                done
                # Linked worktrees keep shared Git metadata outside their checkout trees.
                # Snapshot its directory ACL before denying traversal; never mutate sibling worktrees.
                if [ "${#git_directories[@]}" -gt 0 ]; then
                    getfacl -p -- "${git_directories[@]}" >> "$saved"
                fi
                changed=1
                for git_directory in "${git_directories[@]}"; do
                    setfacl -n -m u:caddy:--- -- "$git_directory"
                done

                # Keep source, Git metadata, and environment files outside the Web root private.
                # Apply every deny before grants so nested Git worktrees retain their own Web roots.
                for checkout in "${checkouts[@]}"; do
                    walk find -P "$checkout" -type d -exec setfacl -m d:u:caddy:--- -- {} +
                    walk setfacl -P -R -n -m u:caddy:--- -- "$checkout"
                done

                for index in "${!checkouts[@]}"; do
                    document_root=${roots[$index]}
                    application=${applications[$index]}
                    storage_target=${storage[$index]}
                    ancestor=$document_root
                    while [ "$ancestor" != / ]; do
                        ancestor=$(dirname -- "$ancestor")
                        if caddy_can x "$ancestor"; then
                            continue
                        fi
                        grant=$(passage_grant "$ancestor")
                        if ! root_owned "$ancestor"; then
                            # The checkout, the directories between it and the Web root, and the managed user's own
                            # directories above it.
                            setfacl -n -m "$grant" -- "$ancestor"
                        else
                            case $'\n'"$root_grants" in
                                *$'\n'"$ancestor"$'\n'*) ;;
                                *)
                                    printf 'Caddy cannot pass %s, which was not saved before the walk.\n' "$ancestor" >&2
                                    exit 1
                                    ;;
                            esac
                            (cd / && sudo -n setfacl -n -m "$grant" -- "$ancestor")
                        fi
                    done

                    # The grants keep each entry's mask (-n). A mask that hides an entry from the group class keeps
                    # it hidden from Caddy too, such as a mode 0600 environment file in the Web root.
                    walk find -P "$document_root" -type d -exec setfacl -m d:u:caddy:r-x -- {} +
                    walk setfacl -P -R -n -m u:caddy:r-X -- "$document_root"
                    if [ -n "$storage_target" ]; then
                        setfacl -n -m "$(passage_grant "$application/storage")" -- "$application/storage"
                        setfacl -n -m "$(passage_grant "$application/storage/app")" -- "$application/storage/app"
                        walk find -P "$storage_target" -type d -exec setfacl -m d:u:caddy:r-x -- {} +
                        walk setfacl -P -R -n -m u:caddy:r-X -- "$storage_target"
                    fi
                    if ! caddy_can r "$document_root" || ! caddy_can x "$document_root"; then
                        printf 'Caddy cannot read the Web root %s, whose mode hides it from the group class.\n' "$document_root" >&2
                        exit 1
                    fi
                    served=("$document_root")
                    if [ -n "$storage_target" ]; then
                        if ! caddy_can r "$storage_target" || ! caddy_can x "$storage_target"; then
                            printf 'Caddy cannot read the public storage %s, whose mode hides it from the group class.\n' "$storage_target" >&2
                            exit 1
                        fi
                        served+=("$storage_target")
                    fi
                    # An entry whose mode hides it from the group class stays hidden from Caddy. Name a few of them.
                    unreadable=$( (cd / && sudo -n -u caddy find -P "${served[@]}" -mindepth 1 \
                        \( -type d ! \( -readable -executable \) -print -prune \) \
                        -o \( ! -type l ! -type d ! -readable -print \) 2> /dev/null) | head -n 101 || true)
                    if [ -n "$unreadable" ]; then
                        count=$(printf '%s\n' "$unreadable" | wc -l)
                        if [ "$count" -gt 100 ]; then count='more than 100'; fi
                        printf 'Warning: Caddy cannot read %s of the entries it serves, such as:\n' "$count" >&2
                        printf '%s\n' "$unreadable" | head -n 5 | sed 's/^/  /' >&2
                    fi
                done
                BASH,
        );
    }
}
