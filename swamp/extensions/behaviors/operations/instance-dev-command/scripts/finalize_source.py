"""Finalize one owned source atomically with a durable local removal receipt."""
import json
import os
import shutil
import subprocess
import sys
from pathlib import Path


def finalize(value):
    member, captured = value['member'], value['capture']
    checkout = Path(member['checkout_path'])
    root = Path(member['source_root'])
    if checkout.parent.parent != root or checkout.is_symlink() or root.is_symlink():
        raise ValueError('instance.checkout_path_unsafe')
    if not root.exists() and captured['source_identity'] == 'absent':
        return {'instance_id':member['instance_id'],'ready':True}
    if root.resolve() != root or not root.is_dir() or root.stat().st_uid != os.geteuid():
        raise ValueError('instance.checkout_path_unsafe')
    receipt = root / ('.orbit-removal-'+value['removal_id']+'-'+str(member['instance_id'])+'.json')
    quarantine = checkout.with_name(checkout.name+'.orbit-removing-'+value['removal_id'])
    expected = {'instance_id':member['instance_id'],'removal_id':value['removal_id'],'source_digest':captured['source_digest'],'identity':captured['source_identity']}
    if receipt.exists():
        if receipt.is_symlink() or json.loads(receipt.read_text()) != expected:
            raise ValueError('instance.removal_conflict')
    else:
        if captured['source_identity'] != 'absent':
            info=checkout.lstat()
            if not checkout.is_dir() or str(info.st_dev)+':'+str(info.st_ino) != captured['source_identity'] or info.st_uid != os.geteuid():
                raise ValueError('instance.removal_conflict')
        elif checkout.exists():
            raise ValueError('instance.removal_conflict')
        descriptor=os.open(receipt,os.O_WRONLY|os.O_CREAT|os.O_EXCL|os.O_NOFOLLOW,0o600)
        with os.fdopen(descriptor,'w') as stream:
            json.dump(expected,stream);stream.flush();os.fsync(stream.fileno())
        rootfd = os.open(root, os.O_RDONLY | os.O_DIRECTORY)
        try:
            os.fsync(rootfd)
        finally:
            os.close(rootfd)
    if checkout.exists():
        info=checkout.lstat()
        if str(info.st_dev)+':'+str(info.st_ino) != expected['identity'] or checkout.is_symlink():
            raise ValueError('instance.removal_conflict')
        # Managed children have already stopped. Refuse an unowned live checkout user.
        for process in Path('/proc').iterdir():
            if not process.name.isdigit() or int(process.name) in (os.getpid(),os.getppid()):
                continue
            try:
                cwd=(process/'cwd').resolve(strict=True)
            except (OSError,RuntimeError):
                continue
            if cwd == checkout or checkout in cwd.parents:
                raise ValueError('instance.source_busy')
        if member['source_layout'] == 'worktree':
            subprocess.run(['/usr/bin/git','-c','core.hooksPath=/dev/null','-C',captured['common_repository_path'],'worktree','remove','--force','--',str(checkout)],check=True,capture_output=True)
        else:
            if quarantine.exists():
                raise ValueError('instance.removal_conflict')
            os.rename(checkout,quarantine)
    if quarantine.exists():
        info=quarantine.lstat()
        if quarantine.is_symlink() or str(info.st_dev)+':'+str(info.st_ino) != expected['identity']:
            raise ValueError('instance.removal_conflict')
        shutil.rmtree(quarantine)
    if checkout.exists() or quarantine.exists():
        raise ValueError('instance.removal_conflict')
    return {'instance_id':member['instance_id'],'ready':True}


if __name__ == '__main__':
    try:
        print(json.dumps(finalize(json.load(sys.stdin))))
    except (ValueError,OSError,KeyError,subprocess.SubprocessError):
        print('{"error_code":"instance.remove_refused"}')
        sys.exit(1)
