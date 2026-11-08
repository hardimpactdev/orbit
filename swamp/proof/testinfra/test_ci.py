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


def test_nonzero_session_exit_cannot_project_all_passed(tmp_path):
    xml = tmp_path / 'cases.xml'
    xml.write_text('<testsuite><testcase name="firewall:allow[x]"/></testsuite>')
    assert project(xml, ['x'], 3)[0]['verdict'] == 'failed'


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
    artifact = output(rows)
    assert len(artifact.encode()) < 4096
    actual = [json.loads(line) for line in artifact.splitlines() if line.startswith('{"id"')]
    assert len(actual) == 47
    assert all(row['verdict'] == 'failed' for row in actual)
    assert actual[0]['failed'][0].startswith('broken')
    assert 'failure details:' not in artifact


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


def test_install_is_timed_separately_and_uses_existing_ci_credentials_only(tmp_path, monkeypatch, capsys):
    import ci_entry
    calls = []
    def run(command, **kwargs):
        calls.append(command)
        return subprocess.CompletedProcess(command, 0, 'installed', '')
    monkeypatch.setattr(ci_entry.subprocess, 'run', run)
    monkeypatch.setattr(sys, 'argv', ['ci_entry.py', 'fresh-fleet', '--install-only', '--evidence', str(tmp_path)])
    assert ci_entry.main() == 0
    row = json.loads(capsys.readouterr().out)
    assert row['id'] == 'spike-install-fresh-fleet' and row['verdict'] == 'passed'
    assert row['secs'] >= 0
    assert calls[-1][-2:] == ['-r', str(HERE / 'requirements.txt')]
    assert all('pytest' not in command for command in calls)


def test_install_failure_is_a_failed_verdict(tmp_path, monkeypatch, capsys):
    import ci_entry
    monkeypatch.setattr(ci_entry.subprocess, 'run', lambda command, **kw: subprocess.CompletedProcess(command, 1, '', 'pip failed'))
    monkeypatch.setattr(sys, 'argv', ['ci_entry.py', 'fresh-fleet', '--install-only', '--evidence', str(tmp_path)])
    assert ci_entry.main() == 1
    row = json.loads(capsys.readouterr().out)
    assert row['cause'] == 'infrastructure' and row['failed']


def test_full_report_with_install_timings_keeps_all_fifty_ids():
    cases = list(metadata())
    lines = []
    for batch in ('fresh-fleet', 'dual-stack'):
        rows = [{'id': row['id'], 'verdict': 'failed', 'secs': 1234.56,
                 'failed': ['same root failure']} for row in cases if row['batch'] == batch]
        lines.append(json.dumps({'id': 'spike-install-' + batch, 'verdict': 'passed', 'secs': 123.45}))
        lines.append(output(rows))
    artifact = '\n'.join(lines)
    assert len(artifact.encode()) < 4096
    parsed = [json.loads(line) for line in artifact.splitlines()]
    assert {row['id'] for row in parsed} == {row['id'] for row in cases} | {'spike-install-fresh-fleet', 'spike-install-dual-stack'}
    assert any(row.get('failed') == ['same root failure'] for row in parsed)
