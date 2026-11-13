"""A failed fleet preparation keeps its cause: the failed Ansible task and its message, not the last 200 characters of a recap."""
import contextlib
import io
import json
import subprocess
import sys
import tempfile
import unittest
from pathlib import Path
from unittest.mock import patch

sys.path.insert(0, str(Path(__file__).resolve().parents[1]))
sys.path.insert(0, str(Path(__file__).resolve().parent))
import fleet as fleet_module
import runner

CASES = Path(__file__).resolve().parent / 'fixtures' / 'allow.cases.yaml'
BATCHES = Path(__file__).resolve().parents[2] / 'batches.yaml'

# What the proof play prints: the failed task is far above the recap, which is all that 200 characters kept.
NOISE = ''.join(f'ok: [nested-gateway] => (item=package-{number})\n' for number in range(120))
ANSIBLE = ('PLAY [Prepare the tested checkout on the proof Gateway] ***\n'
           'TASK [Apply the checkout\'s Swamp grants] ***\n' + NOISE +
           'TASK [Reload the Swamp grants] ***\n'
           'fatal: [localhost]: FAILED! => changed=false\n'
           '  msg: grant file /etc/orbit/grants.yaml is missing\n'
           '  token=hunter2-secret-value\n'
           'TASK [Restart the services] ***\n' + NOISE +
           'PLAY RECAP ***\n'
           'localhost : ok=41 changed=3 unreachable=0 failed=1 skipped=2 rescued=0 ignored=0\n')


def completed(code, stdout='', stderr=''):
    return subprocess.CompletedProcess([], code, stdout, stderr)


def call(*argv):
    out = io.StringIO()
    with contextlib.redirect_stdout(out), contextlib.redirect_stderr(io.StringIO()):
        code = runner.main(list(argv))
    return code, out.getvalue().splitlines()


class MustTest(unittest.TestCase):
    def failure(self, done):
        with patch.object(fleet_module.subprocess, 'run', return_value=done):
            with self.assertRaises(fleet_module.FleetError) as caught:
                fleet_module.Fleet.must(['ansible-playbook'], 'cannot prepare the nested Gateway')
        return str(caught.exception)

    def test_the_failed_task_and_its_message_survive_the_recap(self):
        message = self.failure(completed(2, ANSIBLE))
        self.assertIn('cannot prepare the nested Gateway: exit 2', message)
        self.assertIn('failed task [Reload the Swamp grants]', message)
        self.assertIn('grant file /etc/orbit/grants.yaml is missing', message)
        self.assertIn('failed=1', message)   # the recap stays, after the cause

    def test_the_failed_task_comes_before_the_recap_so_a_cut_keeps_it(self):
        message = self.failure(completed(2, ANSIBLE))
        self.assertLess(message.index('Reload the Swamp grants'), 200)

    def test_a_secret_value_never_reaches_the_message(self):
        message = self.failure(completed(2, ANSIBLE, 'Authorization: Bearer abc.def.ghi\n-----BEGIN OPENSSH PRIVATE KEY-----\nAAAA\n-----END OPENSSH PRIVATE KEY-----\n'))
        for secret in ('hunter2-secret-value', 'abc.def.ghi', 'AAAA'):
            self.assertNotIn(secret, message)
        self.assertIn('token=[redacted]', message)

    def test_a_program_that_is_not_ansible_keeps_the_end_of_its_output(self):
        message = self.failure(completed(1, '', 'start\n' + 'x' * 5000 + '\nthe real cause'))
        self.assertTrue(message.endswith('the real cause'))
        self.assertGreater(len(message), 2000)

    def test_quoted_secret_values_with_spaces_are_redacted_whole(self):
        message = self.failure(completed(1, '', 'password="secret with spaces" api_key=\'another secret\' safe=value'))
        self.assertNotIn('secret', message)
        self.assertIn('safe=value', message)

    def test_a_timeout_names_the_task_that_ran_when_it_struck(self):
        stuck = 'TASK [Wait for the Gateway] ***\n'
        error = subprocess.TimeoutExpired(['ansible-playbook'], 110, output=stuck.encode(), stderr=b'')
        with patch.object(fleet_module.subprocess, 'run', side_effect=error):
            with self.assertRaises(fleet_module.FleetError) as caught:
                fleet_module.Fleet.must(['ansible-playbook'], 'cannot prepare', timeout=110)
        self.assertIn('timed out after 110 seconds', str(caught.exception))
        self.assertIn('last task started [Wait for the Gateway]', str(caught.exception))

    def test_the_message_has_a_bound(self):
        message = self.failure(completed(1, '', 'y' * 50000))
        self.assertLessEqual(len(message), len('cannot prepare the nested Gateway: exit 1, ') + fleet_module.DETAIL_LIMIT)

    def test_a_success_returns_the_process(self):
        with patch.object(fleet_module.subprocess, 'run', return_value=completed(0, 'ok')):
            self.assertEqual(fleet_module.Fleet.must(['true'], 'x').stdout, 'ok')


class RelayTest(unittest.TestCase):
    def test_single_host_failure_before_recap_keeps_the_inner_label_and_task(self):
        output = ('TASK [Reload the Swamp grants] ***\n' + NOISE +
                  'fatal: [localhost]: FAILED! => changed=false\n'
                  '  msg: grant file missing\n'
                  'PLAY RECAP ***\nlocalhost : ok=41 changed=3 failed=1\n')
        inner = 'cannot prepare the nested Gateway prerequisites: exit 2, ' + fleet_module.failure_detail(output, '')
        with patch.object(fleet_module.subprocess, 'run', return_value=completed(1, '', inner)):
            with self.assertRaises(fleet_module.FleetError) as caught:
                fleet_module.prepare()
        message = str(caught.exception)
        self.assertIn('cannot prepare the nested Gateway prerequisites', message)
        self.assertIn('failed task [Reload the Swamp grants]', message)
        self.assertIn('grant file missing', message)

    def test_multiple_host_failures_keep_the_earlier_cause_through_the_relay(self):
        output = ('TASK [Install candidate observer] ***\n'
                  'fatal: [nested-gateway]: FAILED! => changed=false\n'
                  '  msg: checksum mismatch\n' + NOISE +
                  'TASK [Wait for observer] ***\n'
                  'fatal: [nested-app-dev]: FAILED! => changed=false\n'
                  '  msg: observer timed out\n'
                  'PLAY RECAP ***\nnested-gateway : failed=1\nnested-app-dev : failed=1\n')
        inner = 'cannot prepare candidate observers: exit 2, ' + fleet_module.failure_detail(output, '')
        with patch.object(fleet_module.subprocess, 'run', return_value=completed(1, '', inner)):
            with self.assertRaises(fleet_module.FleetError) as caught:
                fleet_module.prepare()
        message = str(caught.exception)
        for expected in ('Install candidate observer', 'checksum mismatch', 'Wait for observer', 'observer timed out'):
            self.assertIn(expected, message)

    def test_the_message_of_the_preparation_subprocess_reaches_the_runner_whole(self):
        inner = fleet_module.FleetError('cannot prepare the nested Gateway prerequisites: exit 2, ' + fleet_module.failure_detail(ANSIBLE, ''))
        with patch.object(fleet_module.subprocess, 'run', return_value=completed(1, '', str(inner))):
            with self.assertRaises(fleet_module.FleetError) as caught:
                fleet_module.prepare()
        self.assertIn('cannot prepare the proof fleet: exit 1', str(caught.exception))
        self.assertIn('failed task [Reload the Swamp grants]', str(caught.exception))
        self.assertIn('grant file /etc/orbit/grants.yaml is missing', str(caught.exception))


class ReportTest(unittest.TestCase):
    CAUSE = 'cannot prepare the nested Gateway: exit 2, failed task [Reload the Swamp grants]: fatal: [localhost]: FAILED! msg: ' + 'grant file missing ' * 30

    def setUp(self):
        self.directory = tempfile.TemporaryDirectory()
        self.addCleanup(self.directory.cleanup)

    def test_setup_prints_the_cause_beyond_200_characters_and_keeps_the_whole_of_it(self):
        with patch.object(fleet_module, 'prepare', side_effect=fleet_module.FleetError(self.CAUSE)):
            code, lines = call('setup', 'dual-stack', '--file', str(CASES), '--batches', str(BATCHES), '--evidence', self.directory.name)
        line = json.loads(lines[0])
        self.assertEqual((code, line['setup']), (1, 'failed'))
        self.assertEqual(line['failed'], ['FleetError: ' + self.CAUSE])
        self.assertIn('"setup": "failed"', lines[0])   # the batch guard matches this text
        self.assertEqual((Path(self.directory.name) / 'batch-dual-stack' / 'fleet-failure.log').read_text(), 'FleetError: ' + self.CAUSE + '\n')

    def test_a_batch_whose_fleet_failed_names_the_task_in_every_verdict_line(self):
        with patch.object(fleet_module, 'from_environment', side_effect=fleet_module.FleetError(self.CAUSE)):
            code, lines = call('batch', 'dual-stack', '--file', str(CASES), '--batches', str(BATCHES), '--evidence', self.directory.name)
        self.assertEqual(code, 1)
        verdicts = [json.loads(line) for line in lines]
        self.assertTrue(verdicts)
        for verdict in verdicts:
            self.assertEqual((verdict['verdict'], verdict['cause']), ('failed', 'infrastructure'))
            self.assertIn('Reload the Swamp grants', verdict['failed'][0])
        self.assertTrue(all(len(line) <= runner.LINE_LIMIT for line in lines))
        self.assertTrue((Path(self.directory.name) / 'fleet-failure.log').is_file())

    def test_a_case_whose_fleet_failed_names_the_task(self):
        with patch.object(fleet_module, 'from_environment', side_effect=fleet_module.FleetError(self.CAUSE)):
            code, lines = call('case', 'family-ipv6', '--file', str(CASES), '--batches', str(BATCHES), '--evidence', self.directory.name)
        self.assertEqual(code, 1)
        self.assertIn('Reload the Swamp grants', json.loads(lines[0])['failed'][0])

    def test_an_evidence_directory_that_cannot_be_written_changes_no_verdict(self):
        blocker = Path(self.directory.name) / 'file'
        blocker.write_text('')
        with patch.object(fleet_module, 'prepare', side_effect=fleet_module.FleetError(self.CAUSE)):
            code, lines = call('setup', 'dual-stack', '--file', str(CASES), '--batches', str(BATCHES), '--evidence', str(blocker))
        self.assertEqual((code, json.loads(lines[0])['setup']), (1, 'failed'))


if __name__ == '__main__':
    unittest.main()
