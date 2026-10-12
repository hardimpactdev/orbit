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
     * Only an entry's owner may change its ACL. On a checkout shared with the task worker, the worker owns what its
     * Git and its agents wrote, so the walk changes each entry's ACL as its owner: the managed user's entries as the
     * managed user, the worker's as the worker through `sudo -u`. Neither account gains anything, since each changes
     * only its own entries, and no pass runs as root or follows a symlink. An entry that neither account owns stops
     * the walk, so no entry keeps access the walk meant to deny.
     *
     * @param  Collection<int, DevelopmentSite>  $sites
     */
    public function command(Collection $sites, ?string $worker = null): RemoteCommand
    {
        $arguments = ['bash', '-seu', '--', $worker ?? ''];
        foreach ($this->walkedSites($sites) as $site) {
            $arguments[] = StoragePath::parse($site->checkoutPath)->value;
            $arguments[] = RelativeWebRoot::validate($site->documentRoot);
            $arguments[] = $site->applicationDirectory();
        }

        return new RemoteCommand(
            arguments: $arguments,
            input: <<<'BASH'
                set -o pipefail
                worker=$1
                shift
                me=$(id -un)
                if [ -n "$worker" ]; then
                    worker_uid=$(id -u "$worker")
                    test "$worker_uid" != 0 && test "$worker_uid" != "$(id -u)"
                fi
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
                # `acl_pass <root> <d|a> <setfacl arguments>...` changes the ACL of every directory (d) or every entry
                # but a symlink (a) below the root, each as its owner. Each account skips a directory it cannot list
                # that the other account owns; that account walks it. The worker walks only when the managed user's
                # walk met an entry the worker owns. Any entry that neither account owns fails the walk.
                acl_pass() {
                    local root=$1 kind=$2 foreign seen entry entry_owner status=0
                    shift 2
                    local type=(! -type l)
                    if [ "$kind" = d ]; then type=(-type d); fi
                    local strangers=(! -user "$me")
                    local count=()
                    foreign=$(mktemp)
                    seen=$(mktemp)
                    if [ -n "$worker" ]; then
                        strangers+=(! -user "$worker")
                        count=(-o \( -user "$worker" -fprintf "$seen" x -false \))
                    fi
                    walk find -P "$root" \
                        \( -user "$me" "${type[@]}" -exec setfacl "$@" -- {} + \) "${count[@]}" \
                        -o \( "${type[@]}" "${strangers[@]}" -print0 \) \
                        -o \( -type d ! \( -readable -executable \) -prune \) >> "$foreign" || status=$?
                    if [ "$status" = 0 ] && [ -s "$seen" ]; then
                        (cd / && walk sudo -n -u "$worker" -- find -P "$root" \
                            \( -user "$worker" "${type[@]}" -exec setfacl "$@" -- {} + \) \
                            -o \( "${type[@]}" "${strangers[@]}" -print0 \) \
                            -o \( -type d ! \( -readable -executable \) -prune \)) >> "$foreign" || status=$?
                    fi
                    # find cannot tell an entry's owner once the entry vanished, so it lists such an entry too.
                    # Only an entry that still exists under an owner other than the two accounts fails the walk.
                    if [ "$status" = 0 ] && [ -s "$foreign" ]; then
                        while IFS= read -r -d '' entry; do
                            if [ ! -e "$entry" ] && [ ! -L "$entry" ]; then continue; fi
                            entry_owner=$(stat -c %U -- "$entry") || { [ -e "$entry" ] || [ -L "$entry" ] || continue; status=1; break; }
                            if [ "$entry_owner" = "$me" ] || { [ -n "$worker" ] && [ "$entry_owner" = "$worker" ]; }; then continue; fi
                            printf 'An entry that neither %s nor the task worker owns: %s\n' "$me" "$entry" >&2
                            status=1
                            break
                        done < "$foreign"
                    fi
                    rm -f -- "$foreign" "$seen"
                    return "$status"
                }
                # `acl_entry <path> <setfacl arguments>...` changes one directory's ACL as its owner.
                acl_entry() {
                    local entry=$1 owner
                    shift
                    test -d "$entry" && test ! -L "$entry"
                    owner=$(stat -c %U -- "$entry")
                    if [ "$owner" = "$me" ]; then
                        setfacl "$@" -- "$entry"
                    elif [ -n "$worker" ] && [ "$owner" = "$worker" ]; then
                        (cd / && sudo -n -u "$worker" -- setfacl "$@" -- "$entry")
                    else
                        printf 'An entry that neither %s nor the task worker owns: %s\n' "$me" "$entry" >&2
                        return 1
                    fi
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
                    test "$(stat -c %U -- "$checkout")" = "$(id -un)"
                    git_directory=$(git -c core.hooksPath=/dev/null -c core.fsmonitor=false -C "$checkout" rev-parse --path-format=absolute --git-common-dir)
                    test -d "$git_directory" && test ! -L "$git_directory"
                    test "$(realpath -e -- "$git_directory")" = "$git_directory"
                    test "$(stat -c %U -- "$git_directory")" = "$(id -un)"
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
                # the rest of each checkout stays, so the failure never widens access. The Git directory is on disk,
                # unlike a tmpfs /tmp, and a snapshot that recovery could not apply stays next to the checkout.
                snapshot=$(mktemp -p "${git_directories[0]}" orbit-caddy-acl.XXXXXX)
                chmod 0600 "$snapshot"
                changed=0
                finish() {
                    result=$?
                    trap - EXIT
                    if [ "$result" != 0 ] && [ "$changed" = 1 ]; then
                        if ! sudo -n setfacl --restore="$snapshot"; then
                            printf 'Caddy access recovery failed; retained ACL snapshot: %s\n' "$snapshot" >&2
                            exit 1
                        fi
                    fi
                    rm -f -- "$snapshot"
                    exit "$result"
                }
                trap finish EXIT
                for index in "${!checkouts[@]}"; do
                    document_root=${roots[$index]}
                    walk getfacl -R -P -p -- "$document_root" >> "$snapshot"
                    ancestor=$document_root
                    while [ "$ancestor" != / ]; do
                        ancestor=$(dirname -- "$ancestor")
                        sudo -n getfacl -p -- "$ancestor" >> "$snapshot"
                    done
                    if [ -n "${storage[$index]}" ]; then
                        getfacl -p -- "${applications[$index]}/storage" "${applications[$index]}/storage/app" >> "$snapshot"
                        walk getfacl -R -P -p -- "${storage[$index]}" >> "$snapshot"
                    fi
                done
                # Linked worktrees keep shared Git metadata outside their checkout trees.
                # Snapshot its directory ACL before denying traversal; never mutate sibling worktrees.
                for git_directory in "${git_directories[@]}"; do
                    getfacl -p -- "$git_directory" >> "$snapshot"
                done
                changed=1
                for git_directory in "${git_directories[@]}"; do
                    setfacl -n -m u:caddy:--- -- "$git_directory"
                done

                # Keep source, Git metadata, and environment files outside the Web root private.
                # Apply every deny before grants so nested Git worktrees retain their own Web roots.
                for checkout in "${checkouts[@]}"; do
                    acl_pass "$checkout" d -m d:u:caddy:---
                    acl_pass "$checkout" a -n -m u:caddy:---
                done

                for index in "${!checkouts[@]}"; do
                    checkout=${checkouts[$index]}
                    document_root=${roots[$index]}
                    application=${applications[$index]}
                    storage_target=${storage[$index]}
                    ancestor=$document_root
                    while [ "$ancestor" != / ]; do
                        ancestor=$(dirname -- "$ancestor")
                        if sudo -n -u caddy python3 -c 'import os, sys; sys.exit(not os.access(sys.argv[1], os.X_OK))' "$ancestor"; then
                            continue
                        fi
                        current_acl=$(sudo -n getfacl -cp -- "$ancestor")
                        caddy_acl=$(printf '%s\n' "$current_acl" | sed -n 's/^user:caddy:\([rwx-]\{3\}\).*$/\1/p')
                        caddy_acl=${caddy_acl:----}
                        mask=$(printf '%s\n' "$current_acl" | sed -n 's/^mask::\([rwx-]\{3\}\).*$/\1/p')
                        if [ -z "$mask" ]; then
                            mask=$(printf '%s\n' "$current_acl" | sed -n 's/^group::\([rwx-]\{3\}\).*$/\1/p')
                        fi
                        sudo -n setfacl -n -m "u:caddy:${caddy_acl%?}x,m::${mask%?}x" -- "$ancestor"
                    done

                    acl_pass "$document_root" d -m d:u:caddy:r-x
                    acl_pass "$document_root" a -m u:caddy:r-X
                    if [ -n "$storage_target" ]; then
                        acl_entry "$application/storage" -m u:caddy:--x
                        acl_entry "$application/storage/app" -m u:caddy:--x
                        acl_pass "$storage_target" d -m d:u:caddy:r-x
                        acl_pass "$storage_target" a -m u:caddy:r-X
                    fi
                    sudo -n -u caddy python3 -c 'import os, sys; sys.exit(not os.access(sys.argv[1], os.R_OK | os.X_OK))' "$document_root"
                done
                BASH,
        );
    }
}
