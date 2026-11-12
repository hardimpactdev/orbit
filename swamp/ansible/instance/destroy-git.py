# Program: instance.destroy-git
# Outcomes: inspected removed refused
# Timeout: 10
# Input: fixed inspect/remove action, admitted paths and optional Node Instance path inventory.
# Result: dependent paths and Instance IDs only; no Git output or file contents.
import json
import os
import shutil
import subprocess
import sys
from pathlib import Path


def git(path, *args):
    environment = {key: value for key, value in os.environ.items() if not key.startswith('GIT_')}
    result = subprocess.run(['/usr/bin/git', '-c', 'core.hooksPath=/dev/null', '-c', 'core.fsmonitor=false',
                             '-C', str(path), *args], capture_output=True, check=True,
                            env={**environment, 'GIT_CONFIG_NOSYSTEM': '1', 'GIT_CONFIG_GLOBAL': '/dev/null',
                                 'GIT_TERMINAL_PROMPT': '0', 'GIT_OPTIONAL_LOCKS': '0'}, timeout=3)
    return result.stdout


def present(path):
    try:
        path.lstat()
        return True
    except FileNotFoundError:
        return False


def inside(path, root):
    return path == root or root in path.parents


def inspect(path, instances):
    path = Path(path)
    if not path.is_absolute() or str(path) == '/' or path.is_symlink():
        raise ValueError
    if not present(path):
        return [], None
    path = path.resolve(strict=True)
    if not present(path / '.git'):
        return [], None
    facts = git(path, 'rev-parse', '--path-format=absolute', '--git-common-dir', '--absolute-git-dir').splitlines()
    if len(facts) != 2:
        raise ValueError
    common, own_gitdir = [Path(os.fsdecode(value)).resolve(strict=True) for value in facts]
    repository_paths = {path: common}
    # A moved worktree's .git still points at the owner, even when its registry path is stale.
    candidates = {}
    for row in instances:
        candidate = Path(row['path'])
        if not candidate.is_absolute():
            raise ValueError
        if present(candidate):
            candidate = candidate.resolve(strict=True)
            candidates.setdefault(candidate, []).append(row['id'])
    dependents = {}

    def read_candidate(candidate):
        if inside(candidate, path) or not present(candidate):
            return
        if not present(candidate / '.git'):
            return
        if candidate not in repository_paths:
            repository_paths[candidate] = Path(os.fsdecode(git(candidate, 'rev-parse', '--path-format=absolute', '--git-common-dir')).rstrip('\n')).resolve(strict=True)
        if inside(repository_paths[candidate], path):
            dependents[candidate] = {'path': str(candidate), 'instance_ids': candidates.get(candidate, [])}

    for candidate in candidates:
        read_candidate(candidate)
    records = git(path, 'worktree', 'list', '--porcelain', '-z').split(b'\0\0')
    for record in records:
        field = next((line[9:] for line in record.split(b'\0') if line.startswith(b'worktree ')), None)
        if field is None:
            continue
        candidate = Path(os.fsdecode(field))
        if present(candidate):
            read_candidate(candidate.resolve(strict=True))
    return list(dependents.values()), common if own_gitdir != common else None


def run(payload):
    paths = payload['paths']
    instances = payload.get('instances', [])
    action = payload['action']
    if action not in ['inspect', 'remove'] or not isinstance(paths, list) or not paths:
        raise ValueError
    inspected = [(path, *inspect(path, instances)) for path in paths]
    dependents = [dependent for _, dependencies, _ in inspected for dependent in dependencies]
    if action == 'inspect':
        return {'outcome': 'inspected', 'data': {'dependents': dependents}}
    if dependents:
        return {'outcome': 'refused', 'data': {'error_code': 'instance.worktree_dependents', 'dependents': dependents}}
    for raw, _, common in inspected:
        path = Path(raw)
        if not present(path):
            continue
        if common is not None:
            # Run from the common repository, not from the worktree being removed. No rm fallback.
            git(common, 'worktree', 'remove', '--force', '--', str(path))
        else:
            shutil.rmtree(path)
    return {'outcome': 'removed', 'data': {}}


def main():
    try:
        value = run(json.load(sys.stdin))
    except (OSError, ValueError, KeyError, TypeError, subprocess.SubprocessError):
        value = {'outcome': 'refused', 'data': {'error_code': 'instance.remove_refused'}}
    print(json.dumps(value))


if __name__ == '__main__':
    main()
