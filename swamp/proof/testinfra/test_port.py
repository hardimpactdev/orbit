"""Offline contract checks: every native case preserves the original ordered checks and negatives."""
import importlib.util
import sys
from pathlib import Path

import pytest
import test_firewall_allow as native
from backend import host
from proof import Proof

ROOT = Path(__file__).resolve().parents[3]
module = importlib.util.spec_from_file_location('proof_source_spec', ROOT / 'swamp/proof/runner/spec.py')
source = importlib.util.module_from_spec(module)
sys.modules[module.name] = source
module.loader.exec_module(source)
SPEC = source.load(ROOT / 'swamp/proof/firewall/allow.cases.yaml', ROOT / 'swamp/proof/batches.yaml')


class RecordingProof:
    def __init__(self):
        self.items = []

    def __getattr__(self, name):
        def record(*args, **kwargs):
            aliases = {'source': 'from', 'target': 'to', 'name': 'as', 'excluding': 'except'}
            self.items.append((name, args, {aliases.get(k, k): v for k, v in kwargs.items()}))
        return record


def expected(case):
    items = [(x.name if x.name != 'cli' else 'command', (), x.args) for x in case.setup]
    for stage in case.stages:
        providers = []
        for step in stage.expect:
            if step.name == 'unchanged':
                for provider in step.args.get('what') or [x for x in SPEC.baseline if x != 'operations']:
                    if provider not in providers:
                        providers.append(provider)
        items.append(('act', tuple(providers), {}))
        items.extend((x.name if x.name != 'cli' else 'command', (), x.args) for x in stage.act)
        items.append(('observe', (), {}))
        items.extend((x.name, (), x.args) for x in stage.expect)
    return items + [('finish', (), {})]


@pytest.mark.parametrize('case', SPEC.cases, ids=lambda c: c.id)
def test_native_case_matches_source(case):
    function = next(f for f in vars(native).values() if callable(f)
                    and any(m.name == 'proof' and m.kwargs['id'] == case.id for m in getattr(f, 'pytestmark', [])))
    recording = RecordingProof()
    function(recording)
    assert recording.items == expected(case)
    marker = next(m for m in function.pytestmark if m.name == 'proof')
    assert marker.kwargs == dict(id=case.id, batch=case.batch, node=case.node, lock=case.lock, timeout=case.timeout)
    negatives = [m for m in function.pytestmark if m.name == 'negative']
    if case.negative:
        assert negatives[0].kwargs == dict(case.negative, do=[(x.name, x.args) for x in case.negative['do']])
    else:
        assert not negatives


def test_port_has_no_missing_or_extra_case():
    actual = [m.kwargs['id'] for f in vars(native).values() if callable(f) for m in getattr(f, 'pytestmark', []) if m.name == 'proof']
    assert len(actual) == 50
    assert sorted(actual) == sorted(c.id for c in SPEC.cases)
    assert sum(bool(c.negative) for c in SPEC.cases) == 13


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
