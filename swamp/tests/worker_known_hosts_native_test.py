"""Run the production local writer in an isolated official Swamp repository."""
import hashlib
import json
import shutil
import sys

import yaml
from native_harness import ROOT, NativeTestCase


class WorkerKnownHostsNative(NativeTestCase):
    native_timeout = 120

    def test_public_pins_reach_atomic_writer_through_native_command(self):
        self.copy_behaviors('operations/node-worker-assets')
        policy = self.repo / 'extensions/behaviors/node-worker'
        policy.mkdir(parents=True)
        shutil.copy(ROOT / 'extensions/behaviors/node-worker/config.ts', policy)
        home = self.repo / 'fixture-account-home'
        home.mkdir()
        wrapper = self.repo / 'sandbox-python'
        wrapper.write_text('#!' + sys.executable + '\n' + '''import pwd,runpy,sys
pwd.getpwuid=lambda uid: type('Account', (), {'pw_dir': HOME})()
runpy.run_path(sys.argv[1],run_name='__main__')
'''.replace('HOME', repr(str(home))))
        wrapper.chmod(0o755)
        workflow = yaml.safe_load((ROOT / 'workflows/workflow-operation-node-worker-known-hosts.yaml').read_text())
        for job in workflow['jobs']:
            job.pop('target')
            job.pop('labels')
            for step in job['steps']:
                step.pop('target')
                step.pop('labels')
                if step['name'] == 'write':
                    step['task']['inputs']['run'] = step['task']['inputs']['run'].replace('/usr/bin/python3', str(wrapper))
        (self.repo / 'workflows/workflow-fixture.yaml').write_text(yaml.safe_dump(workflow, sort_keys=False))
        name = workflow['name']
        self.cli('workflow', 'validate', name)
        content = '10.44.0.9 ssh-ed25519 public-fixture-key\n'
        args = ('--input', 'operation_id=1', '--input', 'node_id=209', '--input', 'kind=node.worker_known_hosts',
                '--input', 'intent=' + json.dumps({'control_node_id': 209, 'known_hosts_content': content,
                                                   'digest': hashlib.sha256(content.encode()).hexdigest()}),
                '--input', 'execution_secrets={}')
        for changed in (True, False):
            run = json.loads(self.cli('workflow', 'run', name, *args).stdout)
            self.assertEqual(run['status'], 'succeeded')
            history = json.loads(self.cli('workflow', 'history', 'get', run['id']).stdout)
            output = json.loads(history['jobs'][0]['steps'][-1]['outputs']['stdout'])
            self.assertTrue(output['known_hosts_ready'])
            self.assertEqual(output['changed'], changed)
            self.assertEqual(output['path'], str(home / '.ssh/known_hosts'))
            self.assertEqual((home / '.ssh/known_hosts').read_text(), content)
