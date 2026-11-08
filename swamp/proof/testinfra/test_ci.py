"""Offline tests for CI projection and the stock xdist Node grouping."""
import json
import os
import shutil
import subprocess
import sys
import xml.etree.ElementTree as ET

import pytest
from ci_entry import HERE, metadata, output, project


@pytest.mark.parametrize('element', ['failure', 'error', 'skipped'])
def test_nonpassing_junit_and_missing_cases_fail_closed(tmp_path, element):
    xml = tmp_path / 'cases.xml'
    xml.write_text(f'<testsuite><testcase name="firewall:allow[x]@app-dev" time="1.2"><{element} message="broken"/></testcase></testsuite>')
    rows = project(xml, ['x', 'missing'])
    assert [row['verdict'] for row in rows] == ['failed', 'failed']
    assert rows[0]['secs'] == 1.2


def test_duplicate_junit_is_failure(tmp_path):
    xml = tmp_path / 'cases.xml'
    xml.write_text('<testsuite><testcase name="firewall:allow[x]"/><testcase name="firewall:allow[x]"/></testsuite>')
    assert project(xml, ['x'])[0]['verdict'] == 'failed'


def test_normal_ci_runner_dispatches_only_canonical_firewall_file():
    script = '''import sys
from pathlib import Path
from unittest.mock import patch
sys.path.insert(0, str(Path.cwd() / 'swamp/proof/runner'))
import runner
with patch.object(runner.subprocess, 'call', return_value=0) as call:
    assert runner.main(['batch', 'fresh-fleet', '--file', 'swamp/proof/firewall/allow.cases.yaml',
                        '--only', 'new-restricted-allow', '--evidence', '/tmp/unused-proof-dispatch']) == 0
    argv = call.call_args.args[0]
    assert argv[1].endswith('/testinfra/ci_entry.py')
    assert argv[-2:] == ['--parallel', '4']
    assert 'new-restricted-allow' in argv
'''
    done = subprocess.run([sys.executable, '-I', '-c', script], cwd=HERE.parents[2],
                          capture_output=True, text=True, timeout=15)
    assert done.returncode == 0, done.stdout + done.stderr


def test_all_fresh_fleet_durations_and_failures_fit_ci_output():
    cases = [row for row in metadata() if row['batch'] == 'fresh-fleet']
    rows = [{'id': row['id'], 'verdict': 'failed', 'secs': 1234.56, 'failed': ['broken' * 100]} for row in cases]
    artifact = output(rows, ['21 failed, 29 deselected in 1000.00s', '26 failed, 24 deselected in 1000.00s'],
                      {'install_secs': 23.55, 'case_phase_secs': 2001.23, 'returncodes': [1, 1]})
    assert len(artifact.encode()) < 4096
    actual = [json.loads(line) for line in artifact.splitlines() if line.startswith('{"id"')]
    assert len(actual) == 47
    assert all(row['verdict'] == 'failed' for row in actual)


def test_ci_filter_groups_nodes_before_xdist_collects(tmp_path):
    shutil.copy(HERE / 'conftest.py', tmp_path)
    shutil.copy(HERE / 'pytest.ini', tmp_path)
    (tmp_path / 'test_smoke.py').write_text('''import pytest
import os
from pathlib import Path
def worker(name):
    Path(__file__).with_name(name).write_text(os.environ['PYTEST_XDIST_WORKER'])
@pytest.mark.proof(id='a', batch='fresh-fleet', node='app-dev', lock='node', timeout=30)
def test_a(): worker('a')
@pytest.mark.proof(id='b', batch='fresh-fleet', node='app-prod', lock='node', timeout=30)
def test_b(): worker('b')
@pytest.mark.proof(id='c', batch='fresh-fleet', node='app-dev', lock='node', timeout=30)
def test_c(): worker('c')
@pytest.mark.proof(id='d', batch='fresh-fleet', node='app-dev', lock='all', timeout=30)
def test_d(): raise AssertionError('must be deselected')
''')
    xml = tmp_path / 'out.xml'
    done = subprocess.run([sys.executable, '-m', 'pytest', str(tmp_path), '-n', '4', '--dist=loadgroup',
                           '--proof-ci-batch=fresh-fleet', '--proof-only=a b c d', '--proof-phase=node',
                           '--proof-results=' + str(tmp_path / 'evidence'), '--junitxml=' + str(xml)],
                          env={**os.environ, 'PYTHONPATH': str(HERE)}, capture_output=True, text=True, timeout=60)
    assert done.returncode == 0, done.stdout + done.stderr
    names = {case.get('name').split('@')[0] for case in ET.parse(xml).iter('testcase')}
    assert names == {'firewall:allow[a]', 'firewall:allow[b]', 'firewall:allow[c]'}
    assert (tmp_path / 'a').read_text() == (tmp_path / 'c').read_text()
    assert (tmp_path / 'a').read_text() != (tmp_path / 'b').read_text()
