"""Verify one observed checkout and its publication before any source removal."""
import json
import os
import subprocess
import sys
from pathlib import Path


def verify(value):
    member, captured = value['member'], value['capture']
    path = Path(member['checkout_path'])
    if captured['source_identity'] == 'absent':
        if path.exists() or path.is_symlink():
            raise ValueError('instance.removal_conflict')
        return {'ready': True}
    if not path.exists() and not path.is_symlink():
        receipt = Path(member['source_root']) / ('.orbit-removal-'+value['removal_id']+'-'+str(member['instance_id'])+'.json')
        expected = {'instance_id':member['instance_id'],'removal_id':value['removal_id'],'source_digest':captured['source_digest'],'identity':captured['source_identity']}
        if receipt.is_symlink() or not receipt.is_file() or json.loads(receipt.read_text()) != expected:
            raise ValueError('instance.removal_conflict')
        return {'ready':True}
    info = path.lstat()
    if not path.is_dir() or path.is_symlink() or str(info.st_dev)+':'+str(info.st_ino) != captured['source_identity']:
        raise ValueError('instance.removal_conflict')
    def git(args):
        return subprocess.run(['/usr/bin/git','-c','core.hooksPath=/dev/null','-c','core.fsmonitor=false','-C',str(path),*args], stdin=subprocess.DEVNULL,capture_output=True,text=True,check=True).stdout.strip()
    if captured['source_commit'] and git(['rev-parse','HEAD']) != captured['source_commit']:
        raise ValueError('instance.removal_conflict')
    if not value['force'] and not member['failed_creation']:
        if git(['status','--porcelain','--untracked-files=all']) or git(['rev-list','--count','HEAD','--not','--remotes=origin']) != '0':
            raise ValueError('instance.unpublished_content')
    return {'ready': True}


if __name__ == '__main__':
    try:
        print(json.dumps(verify(json.load(sys.stdin))))
    except (ValueError,OSError,KeyError,subprocess.SubprocessError):
        print('{"error_code":"instance.remove_refused"}')
        sys.exit(1)
