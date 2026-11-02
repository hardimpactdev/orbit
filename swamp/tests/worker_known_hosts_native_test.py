"""Run the production known-hosts command in an isolated official Swamp repository."""
import hashlib
import unittest
import json
import stat

import yaml
from native_harness import ROOT, NativeTestCase


class WorkerKnownHostsNative(NativeTestCase):
    native_timeout = 120

    def test_public_pins_reach_the_account_known_hosts_through_one_native_command(self):
        home = self.repo / 'fixture-account-home'
        workflow = yaml.safe_load((ROOT / 'workflows/workflow-operation-node-worker-known-hosts.yaml').read_text())
        for job in workflow['jobs']:
            self.assertEqual(job['labels'], {'role': 'swamp'})
            job.pop('labels')
            for step in job['steps']:
                self.assertEqual(step['labels'], {'role': 'swamp'})
                step.pop('labels')
                step['task']['inputs']['run'] = step['task']['inputs']['run'].replace('/home/$USER', str(home))
        (self.repo / 'workflows/workflow-fixture.yaml').write_text(yaml.safe_dump(workflow, sort_keys=False))
        name = workflow['name']
        self.cli('workflow', 'validate', name)
        for content in ('10.44.0.9 ssh-ed25519 public-fixture-key\n', '10.44.0.9 ssh-ed25519 replaced-fixture-key\n'):
            args = ('--input', 'operation_id=1', '--input', 'node_id=209', '--input', 'kind=node.worker_known_hosts',
                    '--input', 'intent=' + json.dumps({'control_node_id': 209, 'known_hosts_content': content,
                                                       'digest': hashlib.sha256(content.encode()).hexdigest()}),
                    '--input', 'execution_secrets={}')
            run = json.loads(self.cli('workflow', 'run', name, *args).stdout)
            self.assertEqual(run['status'], 'succeeded')
            target = home / '.ssh/known_hosts'
            self.assertEqual(target.read_text(), content)
            self.assertEqual(stat.S_IMODE(target.stat().st_mode), 0o600)


if __name__ == '__main__':
    unittest.main()
