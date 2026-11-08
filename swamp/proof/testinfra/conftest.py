"""pytest collects each case; xdist supplies workers; these fixtures own domain isolation."""
import json
import os
import time
import uuid
from pathlib import Path

import checks
import fleet as fleet_module
import pytest
from context import Proof
from isolation import batch_ready, case_lock, lock, restore

ROOT = Path(__file__).resolve().parent
ORDER = ('fresh-fleet', 'dual-stack', 'full-list', 'ufw-fault', 'node-down', 'no-grant')


def pytest_addoption(parser):
    parser.addoption('--negative', action='store_true', help='Run the 13 declared effect-breaking proofs')
    parser.addoption('--proof-results', default=str(ROOT / 'results'), help='Evidence, times and JUnit output directory')
    parser.addoption('--proof-ci-batch', help='SPIKE ONLY: outer CI owns restore and batch setup')
    parser.addoption('--proof-only', default='', help='Space-separated original case IDs')
    parser.addoption('--proof-phase', choices=['node', 'all'], help='CI node groups before exclusive fleet cases')


def pytest_configure(config):
    if hasattr(config, 'workerinput'):
        config.proof_state = Path(config.workerinput['proof_state'])
    else:
        config.proof_state = Path(config.getoption('--proof-results')).resolve() / ('session-' + uuid.uuid4().hex)
        config.proof_state.mkdir(parents=True)
        (config.proof_state / 'done').mkdir()


@pytest.hookimpl(optionalhook=True)
def pytest_configure_node(node):
    node.workerinput['proof_state'] = str(node.config.proof_state)


@pytest.hookimpl(tryfirst=True)
def pytest_pycollect_makeitem(collector, name, obj):
    if name.startswith('test_'):
        metadata = next((m.kwargs for m in getattr(obj, 'pytestmark', []) if m.name == 'proof'), None)
        if metadata:
            return pytest.Function.from_parent(collector, name='firewall:allow[' + metadata['id'] + ']',
                                               callobj=obj)


@pytest.hookimpl(tryfirst=True)
def pytest_collection_modifyitems(config, items):
    proof_items = [item for item in items if item.get_closest_marker('proof')]
    batch = config.getoption('--proof-ci-batch')
    if batch:
        wanted = config.getoption('--proof-only').split()
        phase = config.getoption('--proof-phase')
        removed = [item for item in proof_items if
                   item.get_closest_marker('proof').kwargs['batch'] != batch or
                   item.get_closest_marker('proof').kwargs['id'] not in wanted or
                   item.get_closest_marker('proof').kwargs['lock'] != phase]
        items[:] = [item for item in items if item not in removed]
        config.hook.pytest_deselected(items=removed)
        for item in items:
            marker = item.get_closest_marker('proof')
            if marker:
                item.add_marker(pytest.mark.xdist_group(marker.kwargs['node'] if phase == 'node' else 'fleet'))
    if config.getoption('--negative'):
        removed = [item for item in proof_items if not item.get_closest_marker('negative')]
        items[:] = [item for item in items if item not in removed]
        config.hook.pytest_deselected(items=removed)
    items.sort(key=lambda item: (ORDER.index(item.get_closest_marker('proof').kwargs['batch'])
                                if item.get_closest_marker('proof') else -1))
    config.proof_cases = [item.get_closest_marker('proof').kwargs for item in items if item.get_closest_marker('proof')]
    for item in items:
        marker = item.get_closest_marker('proof')
        if marker:
            item.add_marker(pytest.mark.timeout(marker.kwargs['timeout'], func_only=True))
    # This stock load-scheduler barrier is safe for this spike: only three cases are in
    # the last batch, fewer than the four workers. Refuse a generalization that can deadlock.
    workers = config.getoption('numprocesses', default=0)
    if workers not in (0, None, 4):
        raise pytest.UsageError('This spike supports serial execution or exactly -n 4')
    if len([case for case in config.proof_cases if case['batch'] != 'fresh-fleet']) >= 4:
        raise pytest.UsageError('The spike batch barrier needs a batch-aware xdist scheduler for 4+ later cases')


def prepare(fleet, batch):
    if batch == 'dual-stack':
        failed = checks.ipv6_addresses(Proof(fleet, Path(os.environ['PROOF_STATE']) / 'dual-stack-setup', 'app-dev'),
                                      {'nodes': list(fleet_module.ROLES)})
        if failed:
            raise RuntimeError('\n'.join(failed))


@pytest.fixture
def proof(request):
    config, metadata = request.config, request.node.get_closest_marker('proof').kwargs
    root, case_id = config.proof_state, metadata['id']
    prior = [case['id'] for case in config.proof_cases if ORDER.index(case['batch']) < ORDER.index(metadata['batch'])]
    os.environ['PROOF_STATE'] = str(root)
    began = time.monotonic()
    evidence = root / case_id
    try:
        if config.getoption('--proof-ci-batch'):
            # The product workflow has restored and prepared this batch already.
            with lock(root / 'batch.lock'):
                ready = root / 'ci-nodes.json'
                if not ready.exists():
                    ready.write_text(json.dumps(fleet_module.from_environment().nodes))
                nodes = json.loads(ready.read_text())
        else:
            nodes = batch_ready(root, metadata['batch'], prior, fleet_module.from_environment, prepare)
        fleet = fleet_module.Fleet(nodes)
        negative = request.node.get_closest_marker('negative')
        negative = negative.kwargs if config.getoption('--negative') and negative else None
        with case_lock(root, metadata['node'], metadata['lock'] == 'all'):
            run = Proof(fleet, evidence, metadata['node'], negative)
            started = time.monotonic()
            try:
                yield run
            finally:
                try:
                    checks.cleanup(run)
                finally:
                    run.evidence_text('receipt.json', json.dumps({'command': 'firewall:allow', 'id': case_id,
                                      'seconds': time.monotonic() - started, 'wait_seconds': started - began,
                                      'negative': bool(negative), 'steps': run.log, 'failed': run.failures}, indent=2))
    finally:
        (root / 'done' / case_id).touch()
        with lock(root / 'finish.lock'):
            if (not config.getoption('--proof-ci-batch') and
                    all((root / 'done' / case['id']).exists() for case in config.proof_cases) and not (root / 'final.json').exists()):
                try:
                    seconds = restore(fleet_module.from_environment())
                except Exception as error:
                    (root / 'final.json').write_text(json.dumps({'error': str(error)}))
                    raise
                (root / 'final.json').write_text(json.dumps({'restore_seconds': seconds}))


def pytest_sessionfinish(session, exitstatus):
    if hasattr(session.config, 'workerinput') or session.config.option.collectonly or session.config.getoption('--proof-ci-batch'):
        return
    root = session.config.proof_state
    if list(root.glob('*.json')) and not (root / 'final.json').exists():
        try:
            restore(fleet_module.from_environment())
        except Exception as error:
            session.exitstatus = pytest.ExitCode.TESTS_FAILED
            session.config._proof_restore_error = str(error)


def pytest_terminal_summary(terminalreporter, exitstatus, config):
    error = getattr(config, '_proof_restore_error', None)
    if error:
        terminalreporter.write_sep('!', 'Final restore failed: ' + error, red=True)
    if getattr(config, 'proof_cases', None):
        terminalreporter.write_line('Command: firewall:allow; receipts: ' + str(config.proof_state))
