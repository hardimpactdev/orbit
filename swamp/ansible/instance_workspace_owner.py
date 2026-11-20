"""Root's workspace pass (docs/reference/development-containers.md#who-runs-what, ADR 0268).

`agent` owns the workspace, and `www-data` and `agent` share it through the group `www-data`. A tool that asks for 0755 folders, such as Bun
in `node_modules`, caps their ACL mask, so the other user cannot write there; and a call that only a file's owner may make, such as
`chmod` or `utime` with times, fails on a file of the other user. So root gives the group back what the owner has, as `chmod g+rwX`
would (rwx for a folder, rw for a file and x for a file that someone may run), and gives each entry that the app user owns to `agent`.

Laravel's runtime trees are the app's: in each folder that holds an `artisan` file, a real folder (not a link) at `storage` or
`bootstrap/cache` starts one, and it and every entry under it go to the app user, whoever owns them, `agent` or root. Blade rewrites a
compiled view and then sets its times, which only its owner may do. Anything else at those names keeps the workspace's rule.

The workspace is live while the pass runs: Git, an app or an agent may remove an entry between its listing and its change. An entry
that is gone needs nothing; any other error fails the pass and names its path. Each entry is opened without following a link, and
changed through that descriptor, so a link that replaces an entry never changes what it points at. An entry that is neither a folder
nor a file, such as a link or a socket, gets only its new owner, with `lchown`, never through its target. A file given away loses its
setuid and setgid bits when it goes to `agent`, so `www-data` can never make a program that runs as `agent`. An entry that is not a folder and has another name
(a hard link, `st_nlink` above 1) keeps its owner: its other name may be outside the workspace. Its mode follows the group rule as
before.

It prints each entry it changed, one per line, up to NAMED; above that, only the count. Arguments: the workspace, the app user's
uid and `agent`'s uid.
The container phase runs it as root ("Keep the workspace writable for its group"), and so does a clone's `services` part.
"""
import errno
import os
import stat
import sys

NAMED = 20
# The app's runtime trees, from a folder that holds an `artisan` file.
RUNTIME = (('storage',), ('bootstrap', 'cache'))


def main(workspace, app_uid, agent_uid):
    faults, changed = [], []

    def fault(path, error):
        if error.errno != errno.ENOENT:
            faults.append(f'{path}: {error.strerror}')

    def lacks(mode):
        return mode & 0o070 != 0o070 if stat.S_ISDIR(mode) else stat.S_ISREG(mode) and mode & 0o060 != 0o060

    def takes(status, runtime):
        # A hard-linked entry may also have a name outside the workspace: it is never given away.
        owned = status.st_uid != app_uid if runtime else status.st_uid == app_uid
        return owned and (stat.S_ISDIR(status.st_mode) or status.st_nlink == 1)

    def due(status, runtime):
        return takes(status, runtime) or lacks(status.st_mode)

    def give(fd, path, runtime):
        status = os.fstat(fd)
        mode, given = status.st_mode, takes(status, runtime)
        wanted = stat.S_IMODE(mode)
        if given:
            os.fchown(fd, app_uid if runtime else agent_uid, -1)
            if stat.S_ISREG(mode) and not runtime:
                wanted &= ~(stat.S_ISUID | stat.S_ISGID)
        if lacks(mode):
            wanted |= 0o070 if stat.S_ISDIR(mode) else 0o060 | (0o010 if mode & 0o111 else 0)
        # After a change of owner the mode is always written, as the kernel may have cleared bits that `wanted` keeps.
        if given or wanted != stat.S_IMODE(mode):
            os.fchmod(fd, wanted)
            changed.append(path)

    def visit(fd, path, runtime, below):
        # `below` holds the rest of each runtime tree's path from this folder.
        try:
            give(fd, path, runtime)
        except OSError as error:
            fault(path, error)
        try:
            entries = list(os.scandir(fd))
        except OSError as error:
            return fault(path, error)
        if any(entry.name == 'artisan' and entry.is_file(follow_symlinks=False) for entry in entries):
            below += RUNTIME
        for entry in entries:
            child = os.path.join(path, entry.name)
            rest = tuple(parts[1:] for parts in below if parts[0] == entry.name)
            try:
                folder = entry.is_dir(follow_symlinks=False)
                status = entry.stat(follow_symlinks=False)
                # Only a real folder starts a runtime tree; a file or a link at its name is the workspace's.
                child_runtime = runtime or (folder and () in rest)
                if not folder and not stat.S_ISREG(status.st_mode):
                    if takes(status, child_runtime):
                        os.chown(entry.name, app_uid if child_runtime else agent_uid, -1, dir_fd=fd, follow_symlinks=False)
                        changed.append(child)
                    continue
                if not folder and not due(status, child_runtime):
                    continue
                flags = os.O_RDONLY | os.O_NOFOLLOW | os.O_NONBLOCK | os.O_NOCTTY | (os.O_DIRECTORY if folder else 0)
                child_fd = os.open(entry.name, flags, dir_fd=fd)
            except OSError as error:
                # ELOOP and ENOTDIR: the entry was replaced by a link or a file since its listing; it is not followed.
                if error.errno not in (errno.ELOOP, errno.ENOTDIR):
                    fault(child, error)
                continue
            try:
                if folder:
                    visit(child_fd, child, child_runtime, tuple(parts for parts in rest if parts))
                else:
                    give(child_fd, child, child_runtime)
            except OSError as error:
                fault(child, error)
            finally:
                os.close(child_fd)

    visit(os.open(workspace, os.O_RDONLY | os.O_DIRECTORY | os.O_NOFOLLOW), workspace, False, ())
    if len(changed) > NAMED:
        print(f'{len(changed)} entries changed')
    else:
        for path in changed:
            sys.stdout.buffer.write(os.fsencode(path) + b'\n')
    if faults:
        sys.exit('\n'.join(faults))


if __name__ == '__main__':
    main(sys.argv[1], int(sys.argv[2]), int(sys.argv[3]))
