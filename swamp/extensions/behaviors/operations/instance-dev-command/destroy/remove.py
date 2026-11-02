"""Remove the source: worktrees first, the checkout last, each re-verified just before its removal.

argv[1] is the public destroy intent, argv[2] the source capture of Verify the source. A checkout is renamed to
a quarantine after a durable local receipt and then deleted, so a rerun converges after a crash. Prints
{"ready": true} or {"error_code": ...}.
"""
import json
import os
import shutil
import subprocess
import sys
from pathlib import Path


class Refused(Exception):
    pass


def check(ok, code='instance.removal_conflict'):
    if not ok:
        raise Refused(code)


def identity(path):
    info = path.lstat()
    return f'{info.st_dev}:{info.st_ino}'


def git(path, *arguments):
    return subprocess.run(['/usr/bin/git', '-c', 'core.hooksPath=/dev/null', '-c', 'core.fsmonitor=false', '-C', str(path), *arguments],
                          stdin=subprocess.DEVNULL, capture_output=True, text=True, check=True, timeout=60).stdout.strip()


def receipt_for(intent, member, captured):
    return (Path(member['source_root']) / f".orbit-removal-{intent['removal_id']}-{member['instance_id']}.json",
            {'instance_id': member['instance_id'], 'removal_id': intent['removal_id'], 'source_digest': captured['source_digest'],
             'identity': captured['source_identity']})


def verify(intent, member, captured, admitted):
    path = Path(member['checkout_path'])
    if captured['source_identity'] == 'absent':
        check(not path.exists() and not path.is_symlink())
        return
    if not path.exists() and not path.is_symlink():
        receipt, expected = receipt_for(intent, member, captured)
        check(not receipt.is_symlink() and receipt.is_file() and json.loads(receipt.read_text()) == expected)
        return
    check(path.is_dir() and not path.is_symlink() and identity(path) == captured['source_identity'])
    if captured['source_commit']:
        live = git(path, 'worktree', 'list', '--porcelain', '-z').split('\0')
        check(all(row[9:] in admitted for row in live if row.startswith('worktree ')), 'instance.worktree_inventory_changed')
        check(git(path, 'rev-parse', 'HEAD') == captured['source_commit'])
    if not intent['force'] and not member['failed_creation']:
        check(not git(path, 'status', '--porcelain', '--untracked-files=all')
              and git(path, 'rev-list', '--count', 'HEAD', '--not', '--remotes=origin') == '0', 'instance.unpublished_content')


def busy(checkout):
    for process in Path('/proc').iterdir():
        if not process.name.isdigit() or int(process.name) in (os.getpid(), os.getppid()):
            continue
        try:
            cwd = (process / 'cwd').resolve(strict=True)
        except (OSError, RuntimeError):
            continue
        if cwd == checkout or checkout in cwd.parents:
            return True
    return False


def finalize(intent, member, captured):
    checkout, root = Path(member['checkout_path']), Path(member['source_root'])
    check(checkout.parent.parent == root and not checkout.is_symlink() and not root.is_symlink(), 'instance.checkout_path_unsafe')
    if not root.exists() and captured['source_identity'] == 'absent':
        return
    check(root.resolve() == root and root.is_dir() and root.stat().st_uid == os.geteuid(), 'instance.checkout_path_unsafe')
    receipt, expected = receipt_for(intent, member, captured)
    quarantine = checkout.with_name(checkout.name + '.orbit-removing-' + intent['removal_id'])
    if receipt.exists():
        check(not receipt.is_symlink() and json.loads(receipt.read_text()) == expected)
    else:
        if captured['source_identity'] != 'absent':
            check(checkout.is_dir() and identity(checkout) == captured['source_identity'] and checkout.lstat().st_uid == os.geteuid())
        else:
            check(not checkout.exists())
        descriptor = os.open(receipt, os.O_WRONLY | os.O_CREAT | os.O_EXCL | os.O_NOFOLLOW, 0o600)
        with os.fdopen(descriptor, 'w') as stream:
            json.dump(expected, stream)
            stream.flush()
            os.fsync(stream.fileno())
        rootfd = os.open(root, os.O_RDONLY | os.O_DIRECTORY)
        try:
            os.fsync(rootfd)
        finally:
            os.close(rootfd)
    if checkout.exists():
        check(not checkout.is_symlink() and identity(checkout) == expected['identity'])
        # Managed children have already stopped; an unowned process still in the checkout refuses.
        check(not busy(checkout), 'instance.source_busy')
        if member['source_layout'] == 'worktree':
            subprocess.run(['/usr/bin/git', '-c', 'core.hooksPath=/dev/null', '-C', captured['common_repository_path'], 'worktree', 'remove',
                            '--force', '--', str(checkout)], check=True, capture_output=True, timeout=120)
        else:
            check(not quarantine.exists())
            os.rename(checkout, quarantine)
    if quarantine.exists():
        check(not quarantine.is_symlink() and identity(quarantine) == expected['identity'])
        shutil.rmtree(quarantine)
    check(not checkout.exists() and not quarantine.exists())


def remove(intent, inspection):
    captured = {row['instance_id']: row for row in inspection['members']}
    admitted = {member['checkout_path'] for member in intent['members']}
    for member in sorted(intent['members'], key=lambda member: member['source_layout'] != 'worktree'):
        verify(intent, member, captured[member['instance_id']], admitted)
        finalize(intent, member, captured[member['instance_id']])
    return {'ready': True}


if __name__ == '__main__':
    try:
        result = remove(json.loads(sys.argv[1]), json.loads(sys.argv[2])['source_inspection'])
    except Refused as refusal:
        result = {'error_code': str(refusal)}
    except (ValueError, OSError, KeyError, TypeError, subprocess.SubprocessError):
        result = {'error_code': 'instance.remove_refused'}
    print(json.dumps(result))
    sys.exit(0 if result.get('ready') else 1)
