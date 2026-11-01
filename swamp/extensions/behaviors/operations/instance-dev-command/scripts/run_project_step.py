"""Execute an admitted Project command and retain bounded, redacted output tails."""
import hashlib
import json
import os
import pwd
import re
import selectors
import stat
import subprocess
import sys
from pathlib import Path

from project_environment import load_project_environment


def command_input(value):
    command = value['command']
    if hashlib.sha256(command.encode()).hexdigest() != value['sha256']:
        raise ValueError('instance.setup_identity_changed')
    directory = Path(value['directory'])
    if directory.is_symlink() or not directory.is_dir() or directory.stat().st_uid != os.geteuid():
        raise ValueError('instance.source_ownership_mismatch')
    account = pwd.getpwuid(os.geteuid())
    environment_directory = Path(value.get('environment_directory', directory))
    if not environment_directory.is_relative_to(directory) or any(
            path.is_symlink() for path in (environment_directory, *environment_directory.parents)
            if path == directory or directory in path.parents):
        raise ValueError('instance.source_ownership_mismatch')
    environment = dict(load_project_environment(environment_directory),
                       HOME=account.pw_dir, USER=account.pw_name, LOGNAME=account.pw_name,
                       PATH='/usr/local/bin:/usr/bin:/bin',
                       ORBIT_INSTANCE_ID=str(value['instance_id']), ORBIT_INSTANCE_NAME=value['instance_name'],
                       ORBIT_SEED_PATH=value['seed_path'], ORBIT_SEED_COMMIT=value['seed_commit'])
    requested = value.get('vp_home')
    candidates = [Path(requested)] if requested else [Path('/opt/orbit/vite-plus'), Path(account.pw_dir) / '.vite-plus',
                                                     Path(account.pw_dir) / '.local/share/vite-plus']
    for candidate in candidates:
        binary = candidate / 'bin/vp'
        if not binary.exists():
            if requested:
                raise ValueError('tool.vp_scope_conflict')
            continue
        for path in [candidate, binary]:
            info = path.lstat()
            if stat.S_ISLNK(info.st_mode) or info.st_uid != account.pw_uid or info.st_gid != account.pw_gid or info.st_mode & 0o022:
                raise ValueError('tool.vp_scope_conflict')
        environment.update(VP_HOME=str(candidate), PATH=str(candidate / 'bin') + ':' + environment['PATH'])
        break
    return command, directory, environment


def execute_command(value, timeout_seconds=None):
    command, directory, environment = command_input(value)
    redactions = {command, *environment.values()}
    for key, item in environment.items():
        if re.search(r'TOKEN|SECRET|PASSWORD|CREDENTIAL|(?:^|_)KEY$', key, re.IGNORECASE) and item:
            redactions.add(item)
    redactions |= {line for item in redactions for line in item.splitlines()}
    redactions.discard('')
    secrets = sorted((item.encode() for item in redactions), key=len, reverse=True)
    limit = 4096 + max(map(len, secrets), default=0)
    tails = {'stdout': bytearray(), 'stderr': bytearray()}
    argv = ['/bin/bash', '-eu', '-c', command]
    if timeout_seconds is not None:
        if type(timeout_seconds) is not int or not 1 <= timeout_seconds <= 540:
            raise ValueError('instance.setup_identity_changed')
        argv = ['/usr/bin/timeout', '--kill-after=1s', str(timeout_seconds) + 's', *argv]
    process = subprocess.Popen(argv, env=environment, cwd=directory,
                               stdout=subprocess.PIPE, stderr=subprocess.PIPE)
    with selectors.DefaultSelector() as streams:
        streams.register(process.stdout, selectors.EVENT_READ, 'stdout')
        streams.register(process.stderr, selectors.EVENT_READ, 'stderr')
        while streams.get_map():
            for key, _event in streams.select():
                chunk = os.read(key.fd, 65536)
                if not chunk:
                    streams.unregister(key.fileobj)
                    key.fileobj.close()
                    continue
                tail = tails[key.data]
                tail.extend(chunk)
                if len(tail) > limit:
                    del tail[:-limit]
    code = process.wait()
    output = {'exitCode': code}
    for name, tail in tails.items():
        text = bytes(tail)
        for secret in secrets:
            text = text.replace(secret, b'[REDACTED]')
        output[name] = text[-4096:].decode('utf-8', errors='ignore')
    return output


if __name__ == "__main__":
    result = execute_command(json.load(sys.stdin))
    print(json.dumps(result))
    sys.exit(result["exitCode"])
