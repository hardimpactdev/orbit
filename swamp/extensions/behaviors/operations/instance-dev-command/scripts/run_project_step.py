"""Execute an admitted Project command and retain only bounded, redacted stderr."""
import hashlib
import json
import os
import pwd
import re
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
    # Match #2355: private command text is redacted whole; fragments refer to values.
    secret_values = [item for item in secrets if item != command.encode()]
    capture_limit, tail_limit, fragment = 1048576, 4096, 8
    captured = bytearray()
    truncated = False
    argv = ['/bin/bash', '-eu', '-c', command]
    if timeout_seconds is not None:
        if type(timeout_seconds) is not int or not 1 <= timeout_seconds <= 540:
            raise ValueError('instance.setup_identity_changed')
        argv = ['/usr/bin/timeout', '--kill-after=1s', str(timeout_seconds) + 's', *argv]
    process = subprocess.Popen(argv, env=environment, cwd=directory,
                               stdout=subprocess.DEVNULL, stderr=subprocess.PIPE)
    while chunk := os.read(process.stderr.fileno(), 65536):
        captured.extend(chunk)
        if len(captured) > capture_limit:
            del captured[:-capture_limit]
            truncated = True
    process.stderr.close()
    code = process.wait()

    pattern = re.compile(b'|'.join(re.escape(secret) for secret in secrets))

    def redact(text):
        # One pass never reprocesses a marker inserted by another match.
        return pattern.sub(b'[REDACTED]', text)

    def holds_fragment(line):
        return any(secret[offset:offset + fragment] in line
                   for secret in secret_values
                   for offset in range(len(secret) - fragment + 1))

    # Redact the complete capture before the output cut, then redact again.
    # The capture cap can split a value too: never expose that first line.
    # Normalize before matching: dropping invalid bytes afterwards can join
    # harmless fragments into a known secret after its last redaction.
    whole = redact(bytes(captured).decode('utf-8', errors='replace').encode())
    tail = redact(whole[-tail_limit:])
    lines = tail.split(b'\n')
    if truncated or len(whole) > tail_limit:
        lines = lines[1:]
    kept, used = [], 0
    for line in reversed(lines):
        if holds_fragment(line):
            continue
        size = len(line) + bool(kept)
        # Redaction can expand short values. Remove complete lines rather than
        # making another byte cut that could split a value or UTF-8 character.
        if used + size > tail_limit:
            break
        kept.append(line)
        used += size
    return {'exitCode': code, 'stderr': b'\n'.join(reversed(kept)).decode('utf-8')}



if __name__ == "__main__":
    result = execute_command(json.load(sys.stdin))
    print(json.dumps(result))
    sys.exit(result["exitCode"])
