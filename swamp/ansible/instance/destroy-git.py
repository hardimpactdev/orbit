# Program: instance.destroy-git
# Outcomes: inspected removed refused
# Timeout: 10
# Input: fixed inspect/remove action, admitted paths and optional Node Instance path inventory.
# Result: dependent paths and Instance IDs only; no Git output or file contents.
import json
import os
import shutil
import stat
import subprocess
import sys
import time
from pathlib import Path


def git(path, *args, timeout=3):
    environment = {key: value for key, value in os.environ.items() if not key.startswith('GIT_')}
    result = subprocess.run(['/usr/bin/git', '-c', 'core.hooksPath=/dev/null', '-c', 'core.fsmonitor=false',
                             '-C', str(path), *args], capture_output=True, check=True,
                            env={**environment, 'GIT_CONFIG_NOSYSTEM': '1', 'GIT_CONFIG_GLOBAL': '/dev/null',
                                 'GIT_TERMINAL_PROMPT': '0', 'GIT_OPTIONAL_LOCKS': '0'}, timeout=timeout)
    return result.stdout


def present(path):
    try:
        path.lstat()
        return True
    except FileNotFoundError:
        return False


def canonical(path):
    try:
        return path.resolve()
    except (OSError, RuntimeError):
        return path


def inside(path, root):
    return path == root or root in path.parents


class CheckoutReadFailed(Exception):
    def __init__(self, path, cause, ids=()):
        self.path, self.cause, self.ids = str(path), cause, list(ids)


def pointer_text(path):
    # No FIFO/device reads or unbounded allocation. Never return pointer text to the caller.
    descriptor = os.open(path, os.O_RDONLY | os.O_NONBLOCK)
    try:
        if not stat.S_ISREG(os.fstat(descriptor).st_mode):
            raise ValueError
        data = os.read(descriptor, 4097)
        if len(data) > 4096 or b'\0' in data:
            raise ValueError
        return os.fsdecode(data).strip()
    finally:
        os.close(descriptor)


def resolved_pointer(parent, value):
    if not value or '\n' in value or '\r' in value:
        raise ValueError
    path = Path(value)
    return (path if path.is_absolute() else parent / path).resolve()


def pointer_relationship(checkout, target):
    checkout = checkout.resolve()
    marker = checkout / '.git'
    if not present(marker):
        return 'unknown'
    try:
        if marker.is_dir():
            directory = marker.resolve()
        else:
            value = pointer_text(marker)
            if not value.startswith('gitdir: '):
                return 'unknown'
            if not value[8:] or '\n' in value[8:] or '\r' in value[8:]:
                return 'unknown'
            directory = resolved_pointer(checkout, value[8:])
        if inside(directory, target):
            return 'dependent'
        common = directory / 'commondir'
        if present(common):
            value = pointer_text(common)
            if not value or '\n' in value or '\r' in value:
                return 'unknown'
            directory = resolved_pointer(directory, value)
        if inside(directory, target):
            return 'dependent'
        # An own repository's objects may themselves be symlinked or use alternates.
        pending = [directory / 'objects']
        visited = set()
        while pending:
            objects = pending.pop().resolve()
            if inside(objects, target):
                return 'dependent'
            if objects in visited:
                continue
            visited.add(objects)
            if len(visited) > 16:
                raise ValueError
            alternates = objects / 'info/alternates'
            if present(alternates):
                for value in pointer_text(alternates).splitlines():
                    # Quoted alternates need Git's parser; do not guess at a concealed dependency.
                    if value.startswith('"'):
                        raise ValueError
                    pending.append(resolved_pointer(objects, value))
        return 'unrelated'
    except FileNotFoundError:
        return 'unknown'
    except (OSError, ValueError, RuntimeError):
        # A present unreadable pointer/alternates can conceal a moved worktree or object dependency.
        raise CheckoutReadFailed(checkout, 'unreadable') from None


def inspect(path, instances):
    raw_target = Path(path)
    if not raw_target.is_absolute() or str(raw_target) == '/' or raw_target.is_symlink():
        raise ValueError
    if not present(raw_target):
        return [], None, []
    path = raw_target.resolve(strict=True)
    if not present(path / '.git'):
        return [], None, []
    try:
        facts = git(path, 'rev-parse', '--path-format=absolute', '--git-common-dir', '--absolute-git-dir').splitlines()
        if len(facts) != 2:
            raise ValueError
        common, own_gitdir = [Path(os.fsdecode(value)).resolve(strict=True) for value in facts]
        records = git(path, 'worktree', 'list', '--porcelain', '-z').split(b'\0\0')
    except (OSError, ValueError, subprocess.SubprocessError) as error:
        raise CheckoutReadFailed(raw_target, 'timed_out' if isinstance(error, subprocess.TimeoutExpired) else 'unreadable') from None
    candidates = {}
    for row in instances:
        candidate = Path(row['path'])
        if not candidate.is_absolute() or len(str(candidate)) > 4096 or not isinstance(row['id'], int):
            raise ValueError
        candidates.setdefault(candidate, []).append(row['id'])
    dependents, warnings = {}, []
    # Existing owner-side registrations prove a dependency without reading the other checkout's Git.
    for record in records:
        field = next((line[9:] for line in record.split(b'\0') if line.startswith(b'worktree ')), None)
        if field is None:
            continue
        candidate = Path(os.fsdecode(field))
        try:
            registered_exists = present(candidate)
        except OSError:
            # An owner registration behind an unreadable parent can still hide an existing worktree.
            registered_exists = True
        if inside(common, path) and candidate not in (path, raw_target) and registered_exists:
            ids = [identity for item, identities in candidates.items() if canonical(item) == canonical(candidate) for identity in identities]
            dependents[candidate] = {'path': str(candidate), 'instance_ids': ids[:50]}
    candidate_deadline = time.monotonic() + 3
    for candidate, ids in candidates.items():
        cause = 'unreadable'
        if candidate == raw_target or candidate == path or candidate in dependents:
            continue
        try:
            resolved_candidate = candidate.resolve()
            if inside(resolved_candidate, path):
                relationship = 'dependent'
            elif not present(candidate) or not present(candidate / '.git'):
                relationship = 'unknown'
            else:
                try:
                    remaining = candidate_deadline - time.monotonic()
                    if remaining <= 0:
                        raise subprocess.TimeoutExpired('/usr/bin/git', 3)
                    directory = Path(os.fsdecode(git(candidate, 'rev-parse', '--path-format=absolute', '--git-common-dir', timeout=remaining)).rstrip('\n')).resolve()
                    relationship = 'dependent' if inside(directory, path) else pointer_relationship(candidate, path)
                except (OSError, ValueError, subprocess.SubprocessError) as error:
                    cause = 'timed_out' if isinstance(error, subprocess.TimeoutExpired) else 'unreadable'
                    try:
                        relationship = pointer_relationship(candidate, path)
                    except CheckoutReadFailed:
                        raise CheckoutReadFailed(candidate, cause, ids) from None
            if relationship == 'dependent':
                dependents[candidate] = {'path': str(candidate), 'instance_ids': ids[:50]}
            elif relationship == 'unknown':
                warnings.append({'code': 'instance.checkout_unknown', 'path': str(candidate), 'cause': cause, 'instance_ids': ids[:50]})
        except CheckoutReadFailed as error:
            raise CheckoutReadFailed(candidate, error.cause, ids) from None
        except (OSError, ValueError, RuntimeError):
            warnings.append({'code': 'instance.checkout_unknown', 'path': str(candidate), 'cause': cause, 'instance_ids': ids[:50]})
    return list(dependents.values()), common if own_gitdir != common else None, warnings


def run(payload):
    paths = payload['paths']
    instances = payload.get('instances', [])
    action = payload['action']
    if action not in ['inspect', 'remove'] or not isinstance(paths, list) or not paths:
        raise ValueError
    inspected = [(path, *inspect(path, instances)) for path in paths]
    dependents = [dependent for _, dependencies, _, _ in inspected for dependent in dependencies]
    warnings = [warning for _, _, _, warnings in inspected for warning in warnings]
    diagnostics = {'warnings': warnings[:20]} if warnings else {}
    if len(warnings) > 20:
        diagnostics['other_warnings'] = len(warnings) - 20
    if action == 'inspect':
        return {'outcome': 'inspected', 'data': {'dependents': dependents, **diagnostics}}
    if dependents:
        return {'outcome': 'refused', 'data': {'error_code': 'instance.worktree_dependents', 'dependents': dependents, **diagnostics}}
    for raw, _, common, _ in inspected:
        path = Path(raw)
        if not present(path):
            continue
        if common is not None:
            # Run from the common repository, not from the worktree being removed. No rm fallback.
            git(common, 'worktree', 'remove', '--force', '--', str(path))
        else:
            shutil.rmtree(path)
    return {'outcome': 'removed', 'data': diagnostics}


def main():
    try:
        value = run(json.load(sys.stdin))
    except CheckoutReadFailed as error:
        value = {'outcome': 'refused', 'data': {'error_code': 'instance.remove_refused',
                 'path': error.path[:4096], 'cause': error.cause, 'instance_ids': error.ids[:50]}}
    except (OSError, ValueError, KeyError, TypeError, subprocess.SubprocessError):
        value = {'outcome': 'refused', 'data': {'error_code': 'instance.remove_refused'}}
    print(json.dumps(value))


if __name__ == '__main__':
    main()
