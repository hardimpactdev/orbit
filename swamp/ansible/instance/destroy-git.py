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
import time
from pathlib import Path


def git(path, *args, deadline=None):
    environment = {key: value for key, value in os.environ.items() if not key.startswith('GIT_')}
    result = subprocess.run(['/usr/bin/git', '-c', 'core.hooksPath=/dev/null', '-c', 'core.fsmonitor=false',
                             '-C', str(path), *args], capture_output=True, check=True,
                            env={**environment, 'GIT_CONFIG_NOSYSTEM': '1', 'GIT_CONFIG_GLOBAL': '/dev/null',
                                 'GIT_TERMINAL_PROMPT': '0', 'GIT_OPTIONAL_LOCKS': '0'}, timeout=min(3, remaining(deadline)) if deadline is not None else 3)
    return result.stdout


def present(path):
    try:
        path.lstat()
        return True
    except FileNotFoundError:
        return False


def inside(path, root):
    return path == root or root in path.parents


def remaining(deadline):
    seconds = deadline - time.monotonic()
    if seconds <= 0:
        raise ValueError
    return seconds


def directories(root, deadline):
    # Walk every directory, including dependency folders; never follow symlinks that rmtree would unlink.
    pending = [(root, 0)]
    entries = 0
    while pending:
        remaining(deadline)
        directory, depth = pending.pop()
        if depth > 128:
            raise ValueError
        yield directory
        with os.scandir(directory) as children:
            for child in children:
                remaining(deadline)
                entries += 1
                if entries > 100_000:
                    raise ValueError
                if child.is_dir(follow_symlinks=False):
                    pending.append((Path(child.path), depth + 1))


def repository(path, deadline):
    common = Path(os.fsdecode(git(path, 'rev-parse', '--path-format=absolute', '--git-common-dir', deadline=deadline)).rstrip('\n')).resolve(strict=True)
    own = Path(os.fsdecode(git(path, 'rev-parse', '--absolute-git-dir', deadline=deadline)).rstrip('\n')).resolve(strict=True)
    return common, own


def bare(path):
    return (path / 'HEAD').is_file() and (path / 'objects').is_dir() and (path / 'refs').is_dir()


def inspect(path, instances, deadline):
    path = Path(path)
    if not path.is_absolute() or str(path) == '/' or path.is_symlink():
        raise ValueError
    if not present(path):
        return [], None
    path = path.resolve(strict=True)
    common = own_gitdir = None
    repositories = set()
    if present(path / '.git') or bare(path):
        common, own_gitdir = repository(path, deadline)
        repositories.add(common)
    # A moved worktree's .git still points at the owner, even when its registry path is stale.
    candidates = {}
    for row in instances:
        remaining(deadline)
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
        if not present(candidate / '.git') and not bare(candidate):
            return
        candidate_common, directory = repository(candidate, deadline)
        if inside(candidate_common, path) or inside(directory, path):
            dependents[candidate] = {'path': str(candidate), 'instance_ids': candidates.get(candidate, [])}

    for candidate in candidates:
        read_candidate(candidate)
    # Nested .git directories and bare repositories can own outside worktrees too.
    for directory in directories(path, deadline):
        if directory.name == '.git' or bare(directory):
            repositories.add(directory)
    for directory in sorted(repositories):
        records = git(directory, 'worktree', 'list', '--porcelain', '-z', deadline=deadline).split(b'\0\0')
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
    deadline = time.monotonic() + 6
    inspected = [(path, *inspect(path, instances, deadline)) for path in paths]
    remaining(deadline)
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
