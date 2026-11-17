"""Root's workspace pass (docs/reference/development-containers.md#who-runs-what, ADR 0268).

`agent` owns the workspace, and `www-data` and `agent` share it through the group `www-data`. A tool that asks for 0755 folders, such as Bun
in `node_modules`, caps their ACL mask, so the other user cannot write there; and a call that only a file's owner may make, such as
`chmod` or `utime` with times, fails on a file of the other user. So root gives the group back what the owner has, as `chmod g+rwX`
would (rwx for a folder, rw for a file and x for a file that someone may run), and gives each entry that `from_uid` owns to `to_uid`.

The workspace is live while the pass runs: Git, an app or an agent may remove an entry between its listing and its change. An entry
that is gone needs nothing; any other error fails the pass and names its path. Each entry is opened without following a link, and
changed through that descriptor, so a link that replaces an entry never changes what it points at. An entry that is neither a folder
nor a file, such as a link or a socket, gets only its new owner, with `lchown`, never through its target. A file given away loses its
setuid and setgid bits, so `www-data` can never make a program that runs as `agent`. An entry that is not a folder and has another name
(a hard link, `st_nlink` above 1) keeps its owner: its other name may be outside the workspace. Its mode follows the group rule as
before.

It prints each entry it changed, one per line, up to NAMED; above that, only the count. Arguments: the workspace, `from_uid`, `to_uid`.
The container phase runs it as root ("Keep the workspace writable for its group"), and so does a clone's `services` part.
"""
import errno
import os
import stat
import sys

NAMED = 20


def main(workspace, from_uid, to_uid):
    faults, changed = [], []

    def fault(path, error):
        if error.errno != errno.ENOENT:
            faults.append(f'{path}: {error.strerror}')

    def lacks(mode):
        return mode & 0o070 != 0o070 if stat.S_ISDIR(mode) else stat.S_ISREG(mode) and mode & 0o060 != 0o060

    def takes(status):
        # A hard-linked entry may also have a name outside the workspace: it is never given away.
        return status.st_uid == from_uid and (stat.S_ISDIR(status.st_mode) or status.st_nlink == 1)

    def due(status):
        return takes(status) or lacks(status.st_mode)

    def give(fd, path):
        status = os.fstat(fd)
        mode, given = status.st_mode, takes(status)
        wanted = stat.S_IMODE(mode)
        if given:
            os.fchown(fd, to_uid, -1)
            if stat.S_ISREG(mode):
                wanted &= ~(stat.S_ISUID | stat.S_ISGID)
        if lacks(mode):
            wanted |= 0o070 if stat.S_ISDIR(mode) else 0o060 | (0o010 if mode & 0o111 else 0)
        # After a change of owner the mode is always written, as the kernel may have cleared bits that `wanted` keeps.
        if given or wanted != stat.S_IMODE(mode):
            os.fchmod(fd, wanted)
            changed.append(path)

    def visit(fd, path):
        try:
            give(fd, path)
        except OSError as error:
            fault(path, error)
        try:
            entries = list(os.scandir(fd))
        except OSError as error:
            return fault(path, error)
        for entry in entries:
            child = os.path.join(path, entry.name)
            try:
                folder = entry.is_dir(follow_symlinks=False)
                status = entry.stat(follow_symlinks=False)
                if not folder and not stat.S_ISREG(status.st_mode):
                    if takes(status):
                        os.chown(entry.name, to_uid, -1, dir_fd=fd, follow_symlinks=False)
                        changed.append(child)
                    continue
                if not folder and not due(status):
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
                    visit(child_fd, child)
                else:
                    give(child_fd, child)
            except OSError as error:
                fault(child, error)
            finally:
                os.close(child_fd)

    visit(os.open(workspace, os.O_RDONLY | os.O_DIRECTORY | os.O_NOFOLLOW), workspace)
    if len(changed) > NAMED:
        print(f'{len(changed)} entries changed')
    else:
        for path in changed:
            sys.stdout.buffer.write(os.fsencode(path) + b'\n')
    if faults:
        sys.exit('\n'.join(faults))


if __name__ == '__main__':
    main(sys.argv[1], int(sys.argv[2]), int(sys.argv[3]))
