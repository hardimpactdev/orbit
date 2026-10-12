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
     * as owns the entry, and changes the ACL through `/proc/self/fd`. The snapshot records each entry's device, inode,
     * type, and for a file or directory its inode generation, and a restore applies a saved ACL only to that same
     * inode, so a restore never moves an ACL to an entry that another entry replaced, also when the new entry reuses
     * the freed inode number, and it never changes an owner or adds a setuid or setgid bit. A read grant keeps each
     * entry's mask; only the
     * passage grant on a directory adds execute to it. Root acts only on a directory above every checkout: one that
     * root owns, on a path that root owns from `/` down, which the helper checks again on each component. Root never
     * acts at or below a checkout, which the managed user owns. The snapshots live in a private directory under
     * `/var/tmp`, which neither the worker nor Caddy can enter.
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
                my_uid=$(id -u)
                if [ -n "$worker" ]; then
                    worker_uid=$(id -u "$worker")
                    test "$worker_uid" != 0 && test "$worker_uid" != "$(id -u)"
                fi
                # `fd_acl set <a|d|D> <setfacl arguments>... -- <path>...` changes the ACL of each path. `fd_acl save
                # <directory> <R|-> <path>...` saves each path, and with R everything below it, into <directory>/<uid>
                # of the entry's owner. `fd_acl restore` restores the account's own saved entries on standard input.
                # It opens each path one component at a time from / and never follows a symlink, so a swap anywhere
                # on the path fails instead of redirecting the change. It changes only an entry that the account it
                # runs as owns, through /proc/self/fd, so the entry it checked is the entry it changes. As root, every
                # component from / down must belong to root, so root never acts at or below a checkout. The snapshot
                # holds each entry's device, inode, owner, type, mode, and raw ACLs, and for a file or directory its
                # inode generation, so a restore applies a saved entry only to that same inode, also when a new entry
                # reuses the freed inode number. A restore never changes an owner and never adds a setuid or setgid bit.
                # A restore reports an entry that another entry replaced, or whose generation it cannot read, and fails. `a` skips a symlink, `d` skips what is not a directory, and `D` requires a
                # directory. A vanished entry reports "No such file or directory", which the walk treats as harmless.
                # `find -exec` passes the helper as an argument, so it must never hold an empty pair of braces.
                fd_acl=$(cat <<'PYTHON'
                import base64, errno, fcntl, json, os, stat, subprocess, sys

                me = os.geteuid()
                batch_size = 200
                ACCESS, DEFAULT = 'system.posix_acl_access', 'system.posix_acl_default'
                FS_IOC_GETVERSION = 0x80087601

                def report(path, message):
                    sys.stderr.buffer.write(path + b': ' + message.encode() + b'\n')
                    sys.stderr.flush()

                def proc(fd):
                    return '/proc/self/fd/%d' % fd

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

                def run(arguments, fds):
                    try:
                        return subprocess.run(['setfacl', *arguments, '--', *map(proc, fds)], pass_fds=fds).returncode == 0
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

                def read_acl(fd, name):
                    try:
                        return base64.b64encode(os.getxattr(proc(fd), name)).decode()
                    except OSError as error:
                        if error.errno == errno.ENODATA:
                            return None
                        raise

                def generation(fd, info):
                    # The inode generation of a regular file or directory, which tells a new entry apart from the one
                    # whose freed inode number it reuses. None for another type, or when the filesystem, such as
                    # tmpfs, or the entry's mode does not let the account read it. The ioctl needs a descriptor that
                    # is not O_PATH; reopening the O_PATH descriptor through /proc opens that same inode without
                    # resolving a name, and the fstat below confirms it.
                    if not (stat.S_ISREG(info.st_mode) or stat.S_ISDIR(info.st_mode)):
                        return None
                    try:
                        reader = os.open(proc(fd), os.O_RDONLY | os.O_NONBLOCK | os.O_NOCTTY)
                    except OSError:
                        return None
                    try:
                        opened = os.fstat(reader)
                        if (opened.st_dev, opened.st_ino) != (info.st_dev, info.st_ino):
                            return None
                        return int.from_bytes(fcntl.ioctl(reader, FS_IOC_GETVERSION, bytes(8))[:4], sys.byteorder)
                    except OSError:
                        return None
                    finally:
                        os.close(reader)

                def save(directory, recursive, paths):
                    status, outputs = 0, dict()

                    def record(path, fd, info):
                        if info.st_uid not in outputs:
                            name = os.path.join(directory, str(info.st_uid).encode())
                            outputs[info.st_uid] = open(os.open(name, os.O_WRONLY | os.O_CREAT | os.O_APPEND | os.O_NOFOLLOW, 0o600), 'w')
                        directory_entry = stat.S_ISDIR(info.st_mode)
                        outputs[info.st_uid].write(json.dumps({
                            'path': base64.b64encode(path).decode(), 'dev': info.st_dev, 'ino': info.st_ino, 'uid': info.st_uid,
                            'type': stat.S_IFMT(info.st_mode), 'generation': generation(fd, info),
                            'mode': stat.S_IMODE(info.st_mode), 'access': read_acl(fd, ACCESS),
                            'default': read_acl(fd, DEFAULT) if directory_entry else None,
                        }) + '\n')
                        return directory_entry

                    def visit(path, opener):
                        # Saves one entry and returns a listing descriptor when the walk must descend into it.
                        try:
                            fd = opener()
                        except OSError as error:
                            if error.errno != errno.ENOENT:
                                report(path, error.strerror)
                                return False
                            return None
                        try:
                            info = os.fstat(fd)
                            if stat.S_ISLNK(info.st_mode) or not record(path, fd, info) or not recursive:
                                return None
                            return os.open(proc(fd), os.O_RDONLY | os.O_DIRECTORY)
                        except OSError as error:
                            if error.errno != errno.ENOENT:
                                report(path, error.strerror)
                                return False
                            return None
                        finally:
                            os.close(fd)

                    try:
                        for top in paths:
                            listing = visit(top, lambda: resolve(top))
                            if listing is False:
                                status = 1
                                continue
                            # Only the directories from the top down to the current one stay open.
                            stack = [] if listing is None else [(top, listing, iter(os.listdir(listing)))]
                            while stack:
                                parent, parent_fd, names = stack[-1]
                                name = next(names, None)
                                if name is None:
                                    os.close(parent_fd)
                                    stack.pop()
                                    continue
                                name = os.fsencode(name)
                                path = parent + b'/' + name
                                listing = visit(path, lambda: os.open(name, os.O_PATH | os.O_NOFOLLOW, dir_fd=parent_fd))
                                if listing is False:
                                    status = 1
                                elif listing is not None:
                                    stack.append((path, listing, iter(os.listdir(listing))))
                    finally:
                        for output in outputs.values():
                            output.close()
                    return status

                def remove_acl(target, name):
                    try:
                        os.removexattr(target, name)
                    except OSError as error:
                        if error.errno != errno.ENODATA:
                            raise

                def restore():
                    status = 0
                    for line in sys.stdin.buffer:
                        try:
                            saved = json.loads(line)
                            path = base64.b64decode(saved['path'], validate=True)
                            identity = (int(saved['dev']), int(saved['ino']), int(saved['uid']), int(saved['type']))
                            saved_generation = None if saved['generation'] is None else int(saved['generation'])
                            mode = int(saved['mode'])
                            access, default = (None if saved[key] is None else base64.b64decode(saved[key], validate=True) for key in ('access', 'default'))
                        except (ValueError, KeyError, TypeError):
                            sys.stderr.write('The ACL snapshot holds a malformed entry.\n')
                            status = 1
                            continue
                        if identity[2] != me:
                            report(path, 'Saved for another account')
                            status = 1
                            continue
                        try:
                            fd = resolve(path)
                        except OSError as error:
                            # The saved entry vanished, and its ACL with it.
                            if error.errno != errno.ENOENT:
                                report(path, error.strerror)
                                status = 1
                            continue
                        try:
                            info = os.fstat(fd)
                            current_generation = generation(fd, info)
                            # A file or directory matches only by a generation that both the snapshot and now read.
                            readable = current_generation is not None or not (stat.S_ISREG(info.st_mode) or stat.S_ISDIR(info.st_mode))
                            if (info.st_dev, info.st_ino, info.st_uid, stat.S_IFMT(info.st_mode)) != identity or not readable or current_generation != saved_generation:
                                report(path, 'Replaced since the snapshot, so its ACL stays as it is')
                                status = 1
                                continue
                            # A restore never adds a setuid or setgid bit that the entry lost since the snapshot.
                            mode &= ~((stat.S_ISUID | stat.S_ISGID) & ~info.st_mode)
                            target = proc(fd)
                            if access is None:
                                remove_acl(target, ACCESS)
                                os.chmod(target, mode)
                            else:
                                os.chmod(target, mode)
                                os.setxattr(target, ACCESS, access)
                            if stat.S_ISDIR(info.st_mode):
                                if default is None:
                                    remove_acl(target, DEFAULT)
                                else:
                                    os.setxattr(target, DEFAULT, default)
                        except OSError as error:
                            report(path, error.strerror)
                            status = 1
                        finally:
                            os.close(fd)
                    return status

                if sys.argv[1:2] == ['restore'] and len(sys.argv) == 2:
                    sys.exit(restore())
                if sys.argv[1:2] == ['save'] and len(sys.argv) > 4 and sys.argv[3] in ('R', '-'):
                    sys.exit(save(os.fsencode(sys.argv[2]), sys.argv[3] == 'R', [os.fsencode(path) for path in sys.argv[4:]]))
                if sys.argv[1:2] == ['set'] and len(sys.argv) > 3 and sys.argv[2] in ('a', 'd', 'D') and '--' in sys.argv:
                    split = sys.argv.index('--')
                    sys.exit(change(sys.argv[2], sys.argv[3:split], [os.fsencode(path) for path in sys.argv[split + 1:]]))
                sys.exit('Usage: fd_acl set <a|d|D> <setfacl arguments>... -- <path>... | fd_acl save <directory> <R|-> <path>... | fd_acl restore')
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
                # or replace them. A snapshot that recovery could not apply stays there. `ancestors/0` holds only the
                # root-owned directories above the checkouts that root grants, and only root restores it. `entries`
                # holds everything else in one mode 0600 file per owner, and each account gets only its own file.
                # A new walk removes the account's retained snapshots that are older than three days.
                find /var/tmp -mindepth 1 -maxdepth 1 -type d -name 'orbit-caddy-acl.*' -user "$me" -mtime +3 -exec rm -rf -- '{}' + 2> /dev/null || true
                snapshots=$(mktemp -d -p /var/tmp orbit-caddy-acl.XXXXXX)
                test "$(stat -c '%U %a' -- "$snapshots")" = "$me 700"
                mkdir -m 700 -- "$snapshots/entries" "$snapshots/ancestors"
                changed=0
                finish() {
                    result=$?
                    trap - EXIT
                    if [ "$result" != 0 ] && [ "$changed" = 1 ]; then
                        recovered=1
                        if [ -f "$snapshots/entries/$my_uid" ]; then
                            python3 -I -c "$fd_acl" restore < "$snapshots/entries/$my_uid" || recovered=0
                        fi
                        if [ -n "$worker" ] && [ -f "$snapshots/entries/$worker_uid" ]; then
                            (cd / && sudo -n -u "$worker" -- python3 -I -c "$fd_acl" restore) < "$snapshots/entries/$worker_uid" || recovered=0
                        fi
                        if [ -f "$snapshots/ancestors/0" ]; then
                            (cd / && sudo -n python3 -I -c "$fd_acl" restore) < "$snapshots/ancestors/0" || recovered=0
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
                save() {
                    python3 -I -c "$fd_acl" save "$@"
                }
                # `caddy_can <R_OK|X_OK|...> <path>` succeeds when Caddy has that access to the path.
                caddy_can() {
                    (cd / && sudo -n -u caddy python3 -I -c 'import os, sys; sys.exit(not os.access(sys.argv[2], getattr(os, sys.argv[1])))' "$1" "$2")
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
                    save "$snapshots/entries" R "$document_root"
                    ancestor=$document_root
                    while [ "$ancestor" != / ]; do
                        ancestor=$(dirname -- "$ancestor")
                        if ! root_owned "$ancestor"; then
                            save "$snapshots/entries" - "$ancestor"
                        elif ! caddy_can X_OK "$ancestor"; then
                            save "$snapshots/ancestors" - "$ancestor"
                            root_grants+="$ancestor"$'\n'
                        fi
                    done
                    if [ -n "${storage[$index]}" ]; then
                        save "$snapshots/entries" - "${applications[$index]}/storage" "${applications[$index]}/storage/app"
                        save "$snapshots/entries" R "${storage[$index]}"
                    fi
                done
                # Linked worktrees keep shared Git metadata outside their checkout trees.
                # Snapshot its directory ACL before denying traversal; never mutate sibling worktrees.
                if [ "${#git_directories[@]}" -gt 0 ]; then
                    save "$snapshots/entries" - "${git_directories[@]}"
                fi
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
                        if caddy_can X_OK "$ancestor"; then
                            continue
                        fi
                        grant=$(passage_grant "$ancestor")
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

                    # The grants keep each entry's mask (-n). A mask that hides an entry from the group class keeps
                    # it hidden from Caddy and from the worker too, such as a mode 0600 environment file that the
                    # worker moved into the Web root.
                    acl_pass "$document_root" d -m d:u:caddy:r-x
                    acl_pass "$document_root" a -n -m u:caddy:r-X
                    if [ -n "$storage_target" ]; then
                        acl_entry "$application/storage" -n -m "$(passage_grant "$application/storage")"
                        acl_entry "$application/storage/app" -n -m "$(passage_grant "$application/storage/app")"
                        acl_pass "$storage_target" d -m d:u:caddy:r-x
                        acl_pass "$storage_target" a -n -m u:caddy:r-X
                    fi
                    if ! caddy_can R_OK "$document_root" || ! caddy_can X_OK "$document_root"; then
                        printf 'Caddy cannot read the Web root %s, whose mode hides it from the group class.\n' "$document_root" >&2
                        exit 1
                    fi
                    served=("$document_root")
                    if [ -n "$storage_target" ]; then
                        if ! caddy_can R_OK "$storage_target" || ! caddy_can X_OK "$storage_target"; then
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
