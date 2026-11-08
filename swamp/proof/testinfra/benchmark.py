#!/usr/bin/env python3
"""Matched measurements on ONE product-provisioned task VM; creates no VM or worker.

Cold means a new venv installed without the pip cache. Warm reuses that venv.
Both restore the same nested base before each batch; OS page caches are not controlled.
Dependency installation and case wall time are recorded separately.
"""
import argparse
import json
import os
import subprocess
import sys
import time
import xml.etree.ElementTree as ET
from pathlib import Path

HERE = Path(__file__).resolve().parent
REPO = HERE.parents[2]


def call(argv, log=None, timeout=2400, env=None):
    if Path(str(argv[0])).name in ('ssh', 'gh', 'git'):
        argv = ['timeout', str(timeout), *argv]
    if log:
        with Path(log).open('w') as stream:
            return subprocess.run(argv, stdout=stream, stderr=subprocess.STDOUT, timeout=timeout, env=env, cwd=REPO).returncode
    return subprocess.run(argv, capture_output=True, text=True, timeout=timeout, env=env, cwd=REPO)


def baseline_negative(output):
    # Run in a separate interpreter so baseline fleet/spec modules cannot be replaced by spike modules.
    sys.path.insert(0, str(REPO / 'swamp/proof/runner'))
    import runner
    spec = runner.spec_module.load(REPO / 'swamp/proof/firewall/allow.cases.yaml', REPO / 'swamp/proof/batches.yaml')
    fleet = runner.fleet_module.from_environment()
    cases = [case for case in spec.cases if case.negative]
    results = [result for _, result in runner.schedule(cases, 4, lambda case: runner.run_case(case, fleet, spec, output, True))]
    print(json.dumps(results))
    return int(any(result['verdict'] != 'passed' for result in results))


def install(engine, directory, cold):
    started = time.monotonic()
    python = directory / 'bin/python'
    if cold or not python.exists():
        call([sys.executable, '-m', 'venv', '--clear', str(directory)], directory.parent / 'venv.log', 180)
        packages = ['-r', str(HERE / 'requirements.txt')] if engine == 'pytest' else ['PyYAML==6.0.3']
        rc = call([str(python), '-m', 'pip', 'install', '--no-cache-dir', *packages], directory.parent / 'install.log', 240)
        if rc:
            raise RuntimeError('Dependency installation failed; see install.log')
    return python, time.monotonic() - started


def run(engine, temperature, output, negative=False):
    from isolation import restore

    class NestedCLI:
        def cli(self, args):
            done = call(['php', str(REPO / 'apps/cli/orbit'), *args, '--json'], timeout=240,
                        env={'PATH': '/usr/local/bin:/usr/bin:/bin', 'HOME': '/home/orbit', 'ORBIT_HOME': '/home/orbit/.orbit'})
            try:
                document = json.loads(done.stdout)
            except ValueError:
                document = None
            return {'rc': done.returncode, 'json': document}

    fleet = NestedCLI()
    status = fleet.cli(['gateway:status'])
    document = status['json'] or {}
    if status['rc'] or document.get('gateway') != 'nested' or document.get('url', '').rstrip('/') != 'https://10.45.0.1':
        raise RuntimeError('refusing benchmark: the active CLI profile is not the nested Gateway')
    result = {'engine': engine, 'temperature': temperature, 'negative': negative,
              'commit': call(['git', 'rev-parse', 'HEAD'], timeout=30).stdout.strip(),
              'host': os.uname().nodename, 'started_utc': time.strftime('%Y-%m-%dT%H:%M:%SZ', time.gmtime())}
    output.mkdir(parents=True, exist_ok=False)
    venv = HERE / 'results' / ('venv-' + engine)
    python, result['install_seconds'] = install(engine, venv, temperature == 'cold')
    started = time.monotonic()
    evidence = output / 'evidence'
    env = {**os.environ, 'PROOF_EVIDENCE': str(evidence)}
    statuses = []
    if engine == 'pytest':
        argv = [str(python), '-m', 'pytest', str(HERE / 'test_firewall_allow.py'), '-n', '4', '--dist=load',
                '--max-worker-restart=0', '--proof-results=' + str(evidence), '--durations=0',
                '--junitxml=' + str(output / 'junit.xml')]
        statuses.append(call(argv + (['--negative'] if negative else []), output / 'pytest.log', env=env))
    else:
        runner = REPO / 'swamp/proof/runner/runner.py'
        file_args = ['--file', str(REPO / 'swamp/proof/firewall/allow.cases.yaml')]
        result['restore_seconds'] = []
        for batch in ['fresh-fleet'] if negative else ['fresh-fleet', 'dual-stack']:
            result['restore_seconds'].append(restore(fleet))
            if batch == 'dual-stack':
                rc = call([str(python), str(runner), 'setup', batch, *file_args], output / 'setup.log', env=env)
                statuses.append(rc)
                if rc:
                    # Setup failure is never a passing batch; no case is silently skipped.
                    break
            argv = ([str(python), str(HERE / 'benchmark.py'), '_runner-negative', str(evidence)] if negative else
                    [str(python), str(runner), 'batch', batch, '--parallel', '4', *file_args])
            statuses.append(call(argv, output / (batch + '.log'), env=env))
        result['restore_seconds'].append(restore(fleet))
    result['wall_seconds'] = time.monotonic() - started
    result['total_seconds'] = result['wall_seconds'] + result['install_seconds']
    result['returncodes'] = statuses
    receipts = [json.loads(path.read_text()) for path in evidence.rglob('receipt.json')]
    xml_cases = {}
    if engine == 'pytest' and (output / 'junit.xml').exists():
        for case in ET.parse(output / 'junit.xml').iter('testcase'):
            name = case.attrib['name']
            if name.startswith('firewall:allow[') and name.endswith(']'):
                xml_cases[name[len('firewall:allow['):-1]] = ('failed' if case.find('failure') is not None or case.find('error') is not None else 'passed')
    result['cases'] = [{'id': row['id'], 'seconds': row.get('seconds', row.get('secs')),
                        'wait_seconds': row.get('wait_seconds', 0), 'failed': row.get('failed', []),
                        'verdict': xml_cases.get(row['id']) if engine == 'pytest' else row.get('verdict')} for row in receipts]
    wanted = 13 if negative else 50
    result['complete'] = len(receipts) == wanted and len({row['id'] for row in receipts}) == wanted
    if engine == 'pytest':
        result['complete'] = result['complete'] and len(xml_cases) == wanted
    result['failures'] = sum(row['verdict'] != 'passed' for row in result['cases'])
    result['passed'] = result['complete'] and not any(statuses) and not result['failures']
    (output / 'measurement.json').write_text(json.dumps(result, indent=2) + '\n')
    print(json.dumps({key: value for key, value in result.items() if key != 'cases'}), flush=True)
    return result


def main():
    if len(sys.argv) > 1 and sys.argv[1] == '_runner-negative':
        return baseline_negative(sys.argv[2])
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--output', type=Path, required=True)
    parser.add_argument('--negative', action='store_true')
    args = parser.parse_args()
    results = []
    # ABBA order reduces time-of-day and host-load bias. Both sides get two cold and two warm runs.
    for index, (engine, temperature) in enumerate([
        ('runner', 'cold'), ('pytest', 'cold'), ('pytest', 'warm'), ('runner', 'warm'),
        ('pytest', 'cold'), ('runner', 'cold'), ('runner', 'warm'), ('pytest', 'warm')
    ]):
        results.append(run(engine, temperature, args.output / f'{index+1}-{engine}-{temperature}', args.negative))
    (args.output / 'overview.json').write_text(json.dumps(results, indent=2) + '\n')
    return int(not all(row['passed'] for row in results))


if __name__ == '__main__':
    raise SystemExit(main())
