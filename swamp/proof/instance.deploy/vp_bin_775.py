"""Set or observe an existing topology-managed VP bin directory, never install it."""
import argparse
import json
import os
import pwd
import shlex
import stat
import subprocess
from pathlib import Path


def inspect_store(account, candidates, prepare=False):
    store = next((path for path in candidates if (path / 'bin/vp').is_file()), None)
    assert store is not None, 'Topology prerequisite: managed VP store is absent'
    directory, binary = store.lstat(), (store / 'bin/vp').lstat()
    assert stat.S_ISDIR(directory.st_mode), 'VP store must be a directory'
    assert all(info.st_uid == account.pw_uid and info.st_gid == account.pw_gid
               for info in (directory, binary)), 'VP store or executable has the wrong owner'
    assert os.access(store / 'bin/vp', os.X_OK), 'VP binary must be executable'
    if prepare:
        (store / 'bin').chmod(0o775)
    return {'store': str(store), 'bin_mode': stat.S_IMODE((store / 'bin').stat().st_mode)}


def execute(node, argv):
    result = subprocess.run(shlex.split(os.environ['PROOF_EXEC']) +
                            [node, '--argv=' + json.dumps(argv)],
                            check=True, capture_output=True, text=True, timeout=30)
    return json.loads(result.stdout)


def main():
    parser = argparse.ArgumentParser(__doc__)
    parser.add_argument('--node')
    parser.add_argument('--instance', type=int)
    parser.add_argument('--target-user')
    parser.add_argument('--check', action='store_true')
    args = parser.parse_args()
    if args.target_user:
        account = pwd.getpwnam(args.target_user)
        home = Path(account.pw_dir)
        result = inspect_store(account, [Path('/opt/orbit/vite-plus'), home / '.vite-plus',
                                         home / '.local/share/vite-plus'], not args.check)
    else:
        assert args.node and args.instance, 'A topology Node and Instance are required'
        instance = execute('operator', ['orbit', 'instance:show', str(args.instance), '--json'])
        node = execute('operator', ['orbit', 'node:show', str(instance['node_id']), '--json'])
        argv = ['sudo', '-n', '-u', node['user'], '--', 'python3', '-I', '-c',
                Path(__file__).read_text(), '--target-user', node['user']]
        if args.check:
            argv.append('--check')
        result = execute(args.node, argv)
    assert result['bin_mode'] == 0o775, 'Existing VP bin/ must remain 775'
    print(json.dumps(result))


if __name__ == '__main__':
    main()
