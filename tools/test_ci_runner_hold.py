"""Run the ci-runner role's hold script against a fake `incus` that keeps runner state, and check what it stops, records and starts."""
import json
import os
from pathlib import Path
import stat
import subprocess
import sys
import tempfile
import threading
import unittest

ROOT = Path(__file__).resolve().parents[1]
SCRIPT = ROOT / 'apps/gateway/resources/ci-runner/hold.py'

# The fake reads and writes the runners in FIXTURE_RUNNERS: {name: {"running": bool, "service": str, "busy": bool}}. `incus exec`
# answers the script's read, stop and start commands from that state, and every call is logged.
INCUS = '''#!{python}
import json, os, sys
with open(os.environ['FIXTURE_LOG'], 'a') as log:
    log.write(json.dumps(sys.argv[1:]) + '\\n')
path = os.environ['FIXTURE_RUNNERS']
runners = json.load(open(path))
if sys.argv[1] == 'list':
    for name, runner in runners.items():
        print(name + ',' + ('RUNNING' if runner['running'] else 'STOPPED'))
    sys.exit(0)
name = sys.argv[sys.argv.index('--') - 1]
script = sys.argv[-1]
runner = runners[name]
if 'is-active' in script:
    print('service=' + runner['service'])
    if runner['busy']:
        print('busy=1')
elif 'systemctl stop' in script:
    if runner['busy']:
        print('busy')
    else:
        runner['service'] = 'inactive'
        print('stopped')
elif 'systemctl start' in script:
    if runner.get('start_fails'):
        sys.exit(1)
    runner['service'] = 'active'
json.dump(runners, open(path, 'w'))
'''


class CiRunnerHoldTest(unittest.TestCase):
    def setUp(self):
        scratch = tempfile.TemporaryDirectory(prefix='ci-runner-hold-test-')
        self.addCleanup(scratch.cleanup)
        self.root = Path(scratch.name)
        (self.root / 'bin').mkdir()
        incus = self.root / 'bin/incus'
        incus.write_text(INCUS.format(python=sys.executable))
        incus.chmod(incus.stat().st_mode | stat.S_IXUSR)
        self.state = self.root / 'ci-hold'
        self.runners = self.root / 'runners.json'
        self.log = self.root / 'calls.jsonl'

    def given(self, **runners):
        self.runners.write_text(json.dumps({name: {'running': True, 'service': 'active', 'busy': False, **runner}
                                            for name, runner in runners.items()}))

    def call(self, *arguments, check=True):
        env = dict(os.environ, PATH=str(self.root / 'bin') + ':/usr/bin:/bin', FIXTURE_LOG=str(self.log),
                   FIXTURE_RUNNERS=str(self.runners), ORBIT_CI_HOLD_STATE=str(self.state))
        result = subprocess.run([sys.executable, '-I', str(SCRIPT), *arguments], env=env, capture_output=True, text=True, timeout=30)
        if check:
            self.assertEqual(result.returncode, 0, result.stderr)
        return result

    def services(self):
        return {name: runner['service'] for name, runner in json.loads(self.runners.read_text()).items()}

    def recorded(self):
        path = self.state / 'stopped.json'
        return json.loads(path.read_text()) if path.exists() else []

    def stops(self):
        if not self.log.exists():
            return []
        return [call[4] for call in map(json.loads, self.log.read_text().splitlines()) if call[0] == 'exec' and 'systemctl stop' in call[-1]]

    def test_a_hold_stops_idle_runners_only_down_to_the_places_left_to_runners(self):
        self.given(**{'ci-a': {}, 'ci-b': {}, 'ci-c': {}, 'ci-d': {}, 'ci-e': {}})

        self.call('hold', 'instance-8', '5')
        self.assertEqual(self.recorded(), ['ci-a'])
        self.call('hold', 'instance-9', '5')

        # Five places, two holds: two idle runners stop and three run.
        self.assertEqual(self.recorded(), ['ci-a', 'ci-b'])
        self.assertEqual(list(self.services().values()), ['inactive', 'inactive', 'active', 'active', 'active'])

    def test_a_busy_runner_is_never_stopped_and_counts_as_running(self):
        self.given(**{'ci-a': {'busy': True}, 'ci-b': {'busy': True}, 'ci-c': {}, 'ci-d': {}})

        self.call('hold', 'instance-8', '3')

        # Three places, one hold: two may run. Both busy runners run, so both idle ones stop; no stop reaches a busy runner.
        self.assertEqual(self.services(), {'ci-a': 'active', 'ci-b': 'active', 'ci-c': 'inactive', 'ci-d': 'inactive'})
        self.assertEqual(self.recorded(), ['ci-c', 'ci-d'])
        self.assertEqual(self.stops(), ['ci-c', 'ci-d'])

    def test_a_runner_that_turns_busy_before_its_stop_keeps_its_job_and_is_not_recorded(self):
        self.given(**{'ci-a': {}, 'ci-b': {}})
        # The read says idle; the stop reads busy in the same command and stops nothing.
        script = self.root / 'bin/incus'
        script.write_text(script.read_text().replace("if runner['busy']:\n        print('busy')", "if True:\n        print('busy')"))

        self.call('hold', 'instance-8', '1')

        self.assertEqual(self.services(), {'ci-a': 'active', 'ci-b': 'active'})
        self.assertEqual(self.recorded(), [])

    def test_a_rerun_of_hold_or_release_changes_nothing(self):
        self.given(**{'ci-a': {}, 'ci-b': {}, 'ci-c': {}})

        self.call('hold', 'instance-8', '3')
        self.call('hold', 'instance-8', '3')
        self.assertEqual(self.recorded(), ['ci-a'])
        self.assertEqual(self.stops(), ['ci-a'])

        self.call('release', 'instance-8')
        self.call('release', 'instance-8')
        self.assertEqual(set(self.services().values()), {'active'})
        self.assertEqual(self.recorded(), [])

    def test_release_starts_exactly_the_recorded_runners_when_the_last_hold_goes(self):
        # ci-x was stopped by someone else: no release starts it.
        self.given(**{'ci-a': {}, 'ci-b': {}, 'ci-c': {}, 'ci-x': {'service': 'inactive'}})

        self.call('hold', 'instance-8', '3')
        self.call('hold', 'instance-9', '3')
        self.assertEqual(self.recorded(), ['ci-a', 'ci-b'])

        # Another proof's hold stays: nothing starts.
        self.call('release', 'instance-8')
        self.assertEqual(self.services(), {'ci-a': 'inactive', 'ci-b': 'inactive', 'ci-c': 'active', 'ci-x': 'inactive'})

        self.call('release', 'instance-9')
        self.assertEqual(self.services(), {'ci-a': 'active', 'ci-b': 'active', 'ci-c': 'active', 'ci-x': 'inactive'})
        self.assertEqual(self.recorded(), [])

    def test_release_skips_a_runner_that_is_gone_and_keeps_one_whose_start_failed(self):
        self.given(**{'ci-a': {}, 'ci-b': {}, 'ci-c': {}})
        self.call('hold', 'instance-8', '2')
        self.assertEqual(self.recorded(), ['ci-a', 'ci-b'])
        runners = json.loads(self.runners.read_text())
        del runners['ci-a']
        runners['ci-b']['start_fails'] = True
        self.runners.write_text(json.dumps(runners))

        failed = self.call('release', 'instance-8', check=False)

        self.assertNotEqual(failed.returncode, 0)
        self.assertIn('ci-b', failed.stderr)
        self.assertEqual(self.recorded(), ['ci-b'])

    def test_two_proofs_on_one_node_hold_under_one_lock(self):
        self.given(**{f'ci-{index}': {} for index in range(6)})
        calls = [threading.Thread(target=self.call, args=('hold', f'instance-{copy}', '6')) for copy in range(8, 12)]
        for call in calls:
            call.start()
        for call in calls:
            call.join()

        # Six places and four holds: exactly four runners stop, each once, whatever order the holds took the lock in.
        self.assertEqual(len(self.recorded()), 4)
        self.assertEqual(sorted(self.stops()), sorted(self.recorded()))
        self.assertEqual(list(self.services().values()).count('active'), 2)

    def test_the_holds_and_the_record_are_readable_by_root_only(self):
        self.given(**{'ci-a': {}})

        self.call('hold', 'instance-8', '0')

        self.assertEqual(stat.S_IMODE((self.state / 'holds').stat().st_mode), 0o700)
        self.assertEqual(stat.S_IMODE((self.state / 'stopped.json').stat().st_mode), 0o600)

    def test_refuses_a_malformed_call(self):
        self.given()
        for arguments in (['hold', 'instance-8'], ['hold', '../x', '3'], ['release', 'instance-8', '3'], ['stop', 'instance-8']):
            self.assertNotEqual(self.call(*arguments, check=False).returncode, 0, arguments)
        self.assertFalse(self.log.exists())


if __name__ == '__main__':
    unittest.main()
