"""Offline failure-detection and isolation tests for the reusable fixtures."""
import multiprocessing
from pathlib import Path

import pytest
from backend import host
from context import Proof
from isolation import case_lock


def test_negative_cannot_pass_on_unrelated_failure(tmp_path):
    proof = Proof(None, tmp_path, 'app-dev', {'do': [], 'must_fail': ['traffic']})
    proof.failures = ['a different failure']
    with pytest.raises(AssertionError, match='did not fail'):
        proof.finish()
    proof.failures.append('expect probe traffic: blocked, expected connect')
    proof.finish()


def test_negative_rejects_broken_act(tmp_path):
    proof = Proof(None, tmp_path, 'app-dev', {'do': [], 'must_fail': ['traffic']})
    proof.failures = ['act failed']
    with pytest.raises(AssertionError, match='act failed'):
        proof.observe()


def test_incus_backend_keeps_quoted_arguments_and_command_result(monkeypatch):
    import subprocess
    calls = []

    def run(argv, **kwargs):
        calls.append((argv, kwargs))
        return subprocess.CompletedProcess(argv, 7, 'out', 'err')
    monkeypatch.setattr('backend.subprocess.run', run)
    result = host('nested-app-dev').run('printf %s', 'a; $secret')
    assert calls[0][0] == ['incus', 'exec', 'nested-app-dev', '--', 'sh', '-c', "printf 'a; $secret'"]
    assert (result.rc, result.stdout, result.stderr) == (7, 'out', 'err')
    assert calls[0][1]['timeout'] == 60


def take_lock(root, node, exclusive, began, acquired):
    began.set()
    with case_lock(Path(root), node, exclusive):
        acquired.set()


@pytest.mark.parametrize('node,exclusive,blocked', [
    ('app-dev', False, True), ('app-prod', False, False), ('app-prod', True, True),
])
def test_node_and_fleet_locks_exclude_conflicting_processes(tmp_path, node, exclusive, blocked):
    context = multiprocessing.get_context('spawn')
    began, acquired = context.Event(), context.Event()
    process = context.Process(target=take_lock, args=(str(tmp_path), node, exclusive, began, acquired))
    try:
        with case_lock(tmp_path, 'app-dev', False):
            process.start()
            assert began.wait(3), 'child did not start'
            assert acquired.wait(0.1 if blocked else 3) is not blocked
        assert acquired.wait(3), 'child did not acquire released lock'
        process.join(3)
        assert process.exitcode == 0
    finally:
        if process.is_alive():
            process.terminate()
            process.join(3)
