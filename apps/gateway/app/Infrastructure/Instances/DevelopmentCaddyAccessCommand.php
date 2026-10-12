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
     * Git and its agents wrote, and it can replace any entry it can write with a symlink at any moment. So the walk
     * changes each entry's ACL as its owner: the managed user's entries as the managed user, the worker's as the
     * worker through `sudo -u`. An entry that neither account owns stops the walk, so no entry keeps access the walk
     * meant to deny.
     *
     * No change follows a symlink. Every change, and every restore after a failed walk, goes through one helper that
     * opens the path one component at a time from `/` without following a symlink, checks that the account it runs
     * as owns the entry, and changes the ACL through `/proc/self/fd`. A restore never changes an owner. Root acts
     * only on a directory above every checkout: one that root owns, on a path that root owns from `/` down, which
     * the helper checks again on each component. Root never acts at or below a checkout, which the managed user
     * owns. The snapshots live in a private directory under `/var/tmp`, which neither the worker nor Caddy can enter.
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
                # `fd_acl set <a|d|D> <setfacl arguments>... -- <path>...` changes the ACL of each path, and
                # `fd_acl restore` restores the account's own entries of a `getfacl -p` snapshot on standard input.
                # It opens each path one component at a time from / and never follows a symlink, so a swap anywhere
                # on the path fails instead of redirecting the change. It changes only an entry that the account it
                # runs as owns, through /proc/self/fd, so the entry it checked is the entry it changes. As root, every
                # component from / down must belong to root, so root never acts at or below a checkout. A restore
                # drops the owner and group lines, so it never changes an owner. `a` skips a symlink, `d` skips what
                # is not a directory, and `D` requires a directory. A vanished entry reports "No such file or
                # directory", which the walk treats as harmless.
                fd_acl=$(cat <<'PYTHON'
                import errno, os, pwd, stat, subprocess, sys

                me = os.geteuid()
                batch_size = 200

                def report(path, message):
                    sys.stderr.buffer.write(path + b': ' + message.encode() + b'\n')
                    sys.stderr.flush()

                def owned(fd):
                    if me == 0 and os.fstat(fd).st_uid != 0:
                        raise OSError(errno.EPERM, 'Root acts only on a path that root owns from / down')

                def resolve(path):
                    parts = [] if path == b'/' else path[1:].split(b'/')
                    if not path.startswith(b'/') or any(part in (b'', b'.', b'..') for part in parts):
                        raise OSError(errno.EINVAL, 'Not a canonical absolute path')
                    fd = os.open(b'/', os.O_PATH | os.O_DIRECTORY)
                    try:
                        owned(fd)
                        for index, part in enumerate(parts):
                            flags = os.O_PATH | os.O_NOFOLLOW
                            if index < len(parts) - 1:
                                flags |= os.O_DIRECTORY
                            child = os.open(part, flags, dir_fd=fd)
                            os.close(fd)
                            fd = child
                            owned(fd)
                        return fd
                    except BaseException:
                        os.close(fd)
                        raise

                def run(arguments, fds, data=None):
                    names = ['/proc/self/fd/%d' % fd for fd in fds]
                    try:
                        return subprocess.run(['setfacl', *arguments, *([] if data is not None else ['--', *names])], input=data, pass_fds=fds).returncode == 0
                    finally:
                        for fd in fds:
                            os.close(fd)

                def change(kind, arguments, paths):
                    status, fds = 0, []
                    for path in paths:
                        try:
                            fd = resolve(path)
                        except OSError as error:
                            report(path, error.strerror)
                            status = 1
                            continue
                        mode = os.fstat(fd)
                        if kind == 'D' and not stat.S_ISDIR(mode.st_mode):
                            os.close(fd)
                            report(path, 'Not a directory')
                            status = 1
                        elif stat.S_ISLNK(mode.st_mode) or (kind == 'd' and not stat.S_ISDIR(mode.st_mode)):
                            os.close(fd)
                        elif mode.st_uid != me:
                            os.close(fd)
                            report(path, 'Owned by another account')
                            status = 1
                        else:
                            fds.append(fd)
                        if len(fds) >= batch_size:
                            status |= not run(arguments, fds)
                            fds = []
                    if fds:
                        status |= not run(arguments, fds)
                    return status

                def unquote(value):
                    result, index = bytearray(), 0
                    while index < len(value):
                        digits = value[index + 1:index + 4]
                        if value[index] == 0x5c and len(digits) == 3 and all(0x30 <= digit <= 0x37 for digit in digits):
                            result.append(int(digits, 8))
                            index += 4
                        else:
                            result.append(value[index])
                            index += 1
                    return bytes(result)

                def restore():
                    owners = {pwd.getpwuid(me).pw_name.encode(), str(me).encode()}
                    status, fds, text = 0, [], []
                    for block in sys.stdin.buffer.read().split(b'\n\n'):
                        lines = [line for line in block.split(b'\n') if line]
                        if not lines:
                            continue
                        header = dict(line[2:].partition(b': ')[::2] for line in lines if line.startswith(b'# '))
                        entries = [line for line in lines if not line.startswith(b'#')]
                        if b'file' not in header or b'owner' not in header or not entries:
                            sys.stderr.write('The ACL snapshot holds a malformed entry.\n')
                            status = 1
                            continue
                        if header[b'owner'] not in owners:
                            continue
                        path = unquote(header[b'file'])
                        try:
                            fd = resolve(path)
                        except OSError as error:
                            if error.errno != errno.ENOENT:
                                report(path, error.strerror)
                                status = 1
                            continue
                        mode = os.fstat(fd)
                        defaults = any(entry.startswith(b'default:') for entry in entries)
                        # Another entry now holds the path: the snapshot's entry vanished, and its ACL with it.
                        if stat.S_ISLNK(mode.st_mode) or mode.st_uid != me or (defaults and not stat.S_ISDIR(mode.st_mode)):
                            os.close(fd)
                            continue
                        fds.append(fd)
                        flags = b'# flags: ' + header[b'flags'] + b'\n' if b'flags' in header else b''
                        text.append(b'# file: /proc/self/fd/%d\n' % fd + flags + b'\n'.join(entries) + b'\n\n')
                        if len(fds) >= batch_size:
                            status |= not run(['--restore=-'], fds, b''.join(text))
                            fds, text = [], []
                    if fds:
                        status |= not run(['--restore=-'], fds, b''.join(text))
                    return status

                if sys.argv[1:2] == ['restore'] and len(sys.argv) == 2:
                    sys.exit(restore())
                if sys.argv[1:2] == ['set'] and len(sys.argv) > 3 and sys.argv[2] in ('a', 'd', 'D') and '--' in sys.argv:
                    split = sys.argv.index('--')
                    sys.exit(change(sys.argv[2], sys.argv[3:split], [os.fsencode(path) for path in sys.argv[split + 1:]]))
                sys.exit('Usage: fd_acl set <a|d|D> <setfacl arguments>... -- <path>... | fd_acl restore')
                PYTHON
                )
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
                        \( -user "$me" "${type[@]}" -exec python3 -I -c "$fd_acl" set "$kind" "$@" -- {} + \) "${count[@]}" \
                        -o \( "${type[@]}" "${strangers[@]}" -print0 \) \
                        -o \( -type d ! \( -readable -executable \) -prune \) >> "$foreign" || status=$?
                    if [ "$status" = 0 ] && [ -s "$seen" ]; then
                        (cd / && walk sudo -n -u "$worker" -- find -P "$root" \
                            \( -user "$worker" "${type[@]}" -exec python3 -I -c "$fd_acl" set "$kind" "$@" -- {} + \) \
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
                    owner=$(stat -c %U -- "$entry")
                    if [ "$owner" = "$me" ]; then
                        python3 -I -c "$fd_acl" set D "$@" -- "$entry"
                    elif [ -n "$worker" ] && [ "$owner" = "$worker" ]; then
                        (cd / && sudo -n -u "$worker" -- python3 -I -c "$fd_acl" set D "$@" -- "$entry")
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
                # the rest of each checkout stays, so the failure never widens access. The snapshots live in a private
                # directory under /var/tmp, on disk unlike a tmpfs /tmp, where neither the worker nor Caddy can read
                # or replace them. A snapshot that recovery could not apply stays there. `ancestors` holds only the
                # root-owned directories above the checkouts that root grants, and only root restores it. `entries`
                # holds everything else, and each account restores only its own entries from it.
                snapshots=$(mktemp -d -p /var/tmp orbit-caddy-acl.XXXXXX)
                test "$(stat -c '%U %a' -- "$snapshots")" = "$me 700"
                : > "$snapshots/entries"
                : > "$snapshots/ancestors"
                changed=0
                finish() {
                    result=$?
                    trap - EXIT
                    if [ "$result" != 0 ] && [ "$changed" = 1 ]; then
                        recovered=1
                        python3 -I -c "$fd_acl" restore < "$snapshots/entries" || recovered=0
                        if [ -n "$worker" ]; then
                            (cd / && sudo -n -u "$worker" -- python3 -I -c "$fd_acl" restore) < "$snapshots/entries" || recovered=0
                        fi
                        if [ -s "$snapshots/ancestors" ]; then
                            (cd / && sudo -n python3 -I -c "$fd_acl" restore) < "$snapshots/ancestors" || recovered=0
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
                # Root grants Caddy passage through a root-owned directory above the checkouts only when Caddy cannot
                # pass yet, and only such a directory that it saved first.
                root_grants=
                for index in "${!checkouts[@]}"; do
                    document_root=${roots[$index]}
                    walk getfacl -R -P -p -- "$document_root" >> "$snapshots/entries"
                    ancestor=$document_root
                    while [ "$ancestor" != / ]; do
                        ancestor=$(dirname -- "$ancestor")
                        if ! root_owned "$ancestor"; then
                            getfacl -p -- "$ancestor" >> "$snapshots/entries"
                        elif ! sudo -n -u caddy python3 -c 'import os, sys; sys.exit(not os.access(sys.argv[1], os.X_OK))' "$ancestor"; then
                            getfacl -p -- "$ancestor" >> "$snapshots/ancestors"
                            root_grants+="$ancestor"$'\n'
                        fi
                    done
                    if [ -n "${storage[$index]}" ]; then
                        getfacl -p -- "${applications[$index]}/storage" "${applications[$index]}/storage/app" >> "$snapshots/entries"
                        walk getfacl -R -P -p -- "${storage[$index]}" >> "$snapshots/entries"
                    fi
                done
                # Linked worktrees keep shared Git metadata outside their checkout trees.
                # Snapshot its directory ACL before denying traversal; never mutate sibling worktrees.
                for git_directory in "${git_directories[@]}"; do
                    getfacl -p -- "$git_directory" >> "$snapshots/entries"
                done
                changed=1
                for git_directory in "${git_directories[@]}"; do
                    acl_entry "$git_directory" -n -m u:caddy:---
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
                        current_acl=$(getfacl -cp -- "$ancestor")
                        caddy_acl=$(printf '%s\n' "$current_acl" | sed -n 's/^user:caddy:\([rwx-]\{3\}\).*$/\1/p')
                        caddy_acl=${caddy_acl:----}
                        mask=$(printf '%s\n' "$current_acl" | sed -n 's/^mask::\([rwx-]\{3\}\).*$/\1/p')
                        if [ -z "$mask" ]; then
                            mask=$(printf '%s\n' "$current_acl" | sed -n 's/^group::\([rwx-]\{3\}\).*$/\1/p')
                        fi
                        grant="u:caddy:${caddy_acl%?}x,m::${mask%?}x"
                        if ! root_owned "$ancestor"; then
                            # The checkout, the directories between it and the Web root, and the managed user's own
                            # directories above it: each changes as its owner.
                            acl_entry "$ancestor" -n -m "$grant"
                        else
                            case $'\n'"$root_grants" in
                                *$'\n'"$ancestor"$'\n'*) ;;
                                *)
                                    printf 'Caddy cannot pass %s, which was not saved before the walk.\n' "$ancestor" >&2
                                    exit 1
                                    ;;
                            esac
                            (cd / && sudo -n python3 -I -c "$fd_acl" set D -n -m "$grant" -- "$ancestor")
                        fi
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
