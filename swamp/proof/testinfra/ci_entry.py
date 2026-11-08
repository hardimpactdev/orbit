#!/usr/bin/env python3
"""SPIKE ONLY #3250: firewall:allow's CI batch entry. No main/release changes.

The product owns provisioning, restore, IPv6 setup and VM disposal. Stock xdist
loadgroup gives each Node one queue; a second invocation runs fleet-exclusive
cases afterwards. This matches runner placement/isolation without lock-waiting
workers starving runnable Nodes. Both invocations have a concurrency cap of four.
"""
import argparse
import ast
import json
import re
import subprocess
import sys
import time
import xml.etree.ElementTree as ET
from pathlib import Path

HERE = Path(__file__).resolve().parent


def metadata():
    for function in ast.parse((HERE / 'test_firewall_allow.py').read_text()).body:
        if isinstance(function, ast.FunctionDef):
            for decorator in function.decorator_list:
                if isinstance(decorator, ast.Call) and isinstance(decorator.func, ast.Attribute) and decorator.func.attr == 'proof':
                    yield {word.arg: ast.literal_eval(word.value) for word in decorator.keywords}


def project(xml, wanted, returncode=0):
    """Missing, duplicate, skipped and setup/teardown errors all fail closed."""
    found = {}
    for case in ET.parse(xml).iter('testcase'):
        match = re.fullmatch(r'firewall:allow\[(.*)\](?:@[^@]+)?', case.get('name', ''))
        if not match:
            continue
        case_id = match[1]
        errors = [child for child in case if child.tag in ('failure', 'error', 'skipped')]
        row = {'id': case_id, 'verdict': 'failed' if errors or case_id in found else 'passed',
               'secs': round(float(case.get('time', 0)), 2)}
        if errors:
            row['failed'] = [error.get('message', error.tag) for error in errors]
            row['cause'] = 'infrastructure' if any(error.tag != 'failure' for error in errors) else 'assertion'
        found[case_id] = row
    rows = [found.get(case_id, {'id': case_id, 'verdict': 'failed', 'secs': 0,
                                'failed': ['missing pytest result']}) for case_id in wanted]
    # CI deliberately accepts any process exit and projects verdict lines. A
    # session/collection/plugin error must therefore also fail a verdict line.
    if returncode and rows and all(row['verdict'] == 'passed' for row in rows):
        rows[0].update(verdict='failed', cause='infrastructure',
                       failed=[f'pytest session exited {returncode} despite passing testcase results'])
    return rows


def output(rows):
    # Preserve all case identities/verdicts/durations within the product's 4096
    # byte step-output cap. Failure detail is bounded, never converted to a pass.
    verdicts = [{key: row[key] for key in ('id', 'verdict', 'secs')} for row in rows]
    # Reserve final Report space for the other batch and both install verdicts.
    budget = 3600 if len(rows) > 3 else 275
    suffix = []
    seen = set()
    for index, row in enumerate(rows):
        message = '\n'.join(row.get('failed', []))
        if message and message not in seen:
            seen.add(message)
            candidate = {**verdicts[index], 'failed': [message[:160]], 'cause': row.get('cause', 'infrastructure')}
            lines = [json.dumps(candidate if i == index else item) for i, item in enumerate(verdicts)] + suffix
            if len('\n'.join(lines).encode()) <= budget:
                verdicts[index] = candidate
    # Diagnostics belong to verdict objects: the CI report intentionally drops
    # non-verdict lines. Keep all identities/durations ahead of extra diagnostics.
    lines = [json.dumps(item) for item in verdicts] + suffix
    text = '\n'.join(lines)
    if len(text.encode()) > budget:
        raise RuntimeError('complete CI artifact exceeds step output cap')
    return text


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('batch')
    parser.add_argument('--evidence', type=Path, required=True)
    parser.add_argument('--only', default='')
    parser.add_argument('--install-only', action='store_true')
    parser.add_argument('--parallel', type=int, default=4)
    args = parser.parse_args()
    if args.parallel != 4:
        parser.error('this spike requires concurrency limit 4')
    root = args.evidence / ('pytest-' + args.batch)
    root.mkdir(parents=True, exist_ok=True)
    python = args.evidence / 'testinfra-venv/bin/python'
    if args.install_only:
        began = time.monotonic()
        failures = []
        try:
            if not python.exists():
                subprocess.run([sys.executable, '-m', 'venv', str(python.parent.parent)], check=True,
                               timeout=120, capture_output=True, text=True)
            install = subprocess.run([str(python), '-m', 'pip', 'install', '--disable-pip-version-check',
                                      '-r', str(HERE / 'requirements.txt')], capture_output=True, text=True, timeout=240)
            (root / 'install.log').write_text(install.stdout + install.stderr)
            if install.returncode:
                failures = ['pinned pip install failed: ' + install.stderr.strip()[-250:]]
        except (subprocess.SubprocessError, OSError) as error:
            failures = [str(error)[-250:]]
        row = {'id': 'spike-install-' + args.batch, 'verdict': 'failed' if failures else 'passed',
               'secs': round(time.monotonic() - began, 2)}
        if failures:
            row.update(cause='infrastructure', failed=failures)
        print(json.dumps(row), flush=True)
        return int(bool(failures))
    wanted = args.only.split()
    cases = [row for row in metadata() if row['batch'] == args.batch and row['id'] in wanted]
    if not wanted or len(cases) != len(wanted) or len(set(wanted)) != len(wanted):
        parser.error('unknown, duplicate or empty firewall:allow selection')
    install = subprocess.run([sys.executable, str(HERE / 'ci_entry.py'), args.batch, '--install-only',
                              '--evidence', str(args.evidence)], capture_output=True, text=True, timeout=400)
    timing = json.loads(install.stdout)
    if install.returncode or timing['verdict'] != 'passed':
        print(output([{'id': case, 'verdict': 'failed', 'secs': 0,
                       'failed': ['spike install phase failed']} for case in wanted]), flush=True)
        print(json.dumps(timing), flush=True)
        return 1
    began = time.monotonic()
    rows, codes = [], []
    for phase in ('node', 'all'):
        ids = [row['id'] for row in cases if row['lock'] == phase]
        if not ids:
            continue
        xml = root / (phase + '.xml')
        command = [str(python), '-m', 'pytest', str(HERE / 'test_firewall_allow.py'), '-n', '4',
                   '--dist=loadgroup', '--max-worker-restart=0', '--durations=0', '--durations-min=0',
                   '--proof-ci-batch=' + args.batch, '--proof-only=' + ' '.join(ids), '--proof-phase=' + phase,
                   '--proof-results=' + str(root), '--junitxml=' + str(xml), '--tb=short', '--color=no']
        done = subprocess.run(command, capture_output=True, text=True, timeout=3200)
        (root / (phase + '.log')).write_text(done.stdout + done.stderr)
        codes.append(done.returncode)
        rows.extend(project(xml, ids, done.returncode) if xml.exists() else
                    [{'id': case_id, 'verdict': 'failed', 'secs': 0, 'failed': ['pytest produced no JUnit']} for case_id in ids])
    metrics = {'spike': 'pytest-testinfra', 'batch': args.batch, 'case_phase_secs': round(time.monotonic() - began, 2), 'returncodes': codes}
    (root / 'measurement.json').write_text(json.dumps({'metrics': metrics, 'cases': rows}, indent=2))
    print(output(rows), flush=True)
    print(json.dumps(timing), flush=True)
    return int(any(codes) or len(rows) != len(wanted) or any(row['verdict'] != 'passed' for row in rows))


if __name__ == '__main__':
    raise SystemExit(main())
