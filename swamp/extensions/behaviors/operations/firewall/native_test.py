"""Run the shipped workflow with official SSH and the UFW double; no host firewall calls."""
import json
import os
import sys
from pathlib import Path

import yaml

ROOT = Path(__file__).resolve().parents[4]
sys.path.insert(0, str(ROOT / 'tests'))
from native_harness import NativeTestCase

KEY = 'ssh-ed25519 AAAAC3NzaC1lZDI1NTE5AAAAIAEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEB'


class FirewallNative(NativeTestCase):
    def setUp(self):
        super().setUp()
        self.copy_behaviors('operations/firewall')
        self.cli('extension', 'pull', '@swamp/ssh@2026.10.23.1')
        workflow = yaml.safe_load((ROOT / 'workflows/workflow-operation-firewall-direct.yaml').read_text())
        for step in workflow['jobs'][0]['steps']:
            self.assertEqual(step.pop('labels'), {'role': 'swamp'})
        (self.repo / 'workflows/workflow-operation-firewall-direct.yaml').write_text(yaml.safe_dump(workflow))
        (self.repo / 'ufw_double.py').write_text((Path(__file__).parent / 'firewall_test.py').read_text().split('def rule(')[0]
                                                 .replace("SCRIPT = Path(__file__).parent", "SCRIPT = Path(__file__).parent / 'extensions/behaviors/operations/firewall'"))
        self.state = self.repo / 'state.json'
        self.state.write_text(json.dumps({'rows': [], 'active': True}))
        binary = self.repo / 'bin'
        binary.mkdir()
        (binary / 'ssh').write_text('#!' + sys.executable + '\n' + '''import json,os,shlex,sys
from pathlib import Path
if any(x in sys.argv for x in ['-O','-N','-MNf','-M']):raise SystemExit(0)
command=sys.argv[-1]
if '__swamp_sudo_ok__' in command:
 print('__swamp_sudo_ok__')
 command=shlex.split(command)[-1].split('exec </dev/null; ',1)[-1]
args=shlex.split(command)
index=len(args)-1-args[::-1].index('-c')
namespace={'__name__':'native_fixture'}
exec(args[index+1],namespace)
sys.path.insert(0,str(Path.cwd()))
from ufw_double import Ufw
state=Path(os.environ['FIREWALL_STATE']);saved=json.loads(state.read_text())
ufw=Ufw(saved['rows']);ufw.active=saved['active'];namespace['ufw']=ufw
try:print(json.dumps(namespace['converge'](json.loads(args[index+2]))))
except RuntimeError as error:
 print(json.dumps({'error_code':str(error)}));raise SystemExit(1)
finally:state.write_text(json.dumps({'rows':ufw.rows,'active':ufw.active}))
''')
        (binary / 'ssh').chmod(0o755)
        self.native_env = {'PATH': str(binary) + os.pathsep + os.environ['PATH'], 'FIREWALL_STATE': str(self.state)}

    def run_root(self, kind, rule, ensure='present', success=True):
        intent = {'ssh': {'identity_file': '/fixture/key', 'target': {'host': '10.44.0.9', 'user': 'orbit', 'port': 22, 'host_keys': [KEY]}},
                  'rule': {'node_id': 9, 'comment': 'orbit db', **rule}, 'ensure': ensure}
        result = self.cli('workflow', 'run', 'operation-firewall-direct', '--input', 'operation_id=17', '--input', 'node_id=9', '--input', 'kind=' + kind,
                          '--input', 'intent=' + json.dumps(intent), '--input', 'execution_secrets={}', success=success)
        history = json.loads(self.cli('workflow', 'history', 'get', json.loads(result.stdout)['id']).stdout)
        steps = {step['name']: step for step in history['jobs'][0]['steps']}
        return steps, json.loads(steps['apply']['outputs']['stdout'])

    def test_allow_deny_order_and_remove_through_the_native_root(self):
        self.cli('workflow', 'validate', 'operation-firewall-direct')
        deny = {'action': 'deny', 'port': '5432', 'protocol': 'tcp', 'source': 'any'}
        allow = {'action': 'allow', 'port': '5432', 'protocol': 'tcp', 'source': '192.0.2.10'}
        self.assertEqual(self.run_root('firewall.deny', deny)[1], {'backend': 'active'})
        steps, terminal = self.run_root('firewall.allow', allow)
        self.assertEqual((terminal, steps['accepted']['status']), ({'backend': 'active'}, 'succeeded'))
        rows = json.loads(self.state.read_text())['rows']
        self.assertEqual([(row['to'], row['action'], row['source']) for row in rows[:2]],
                         [('5432/tcp', 'allow', '192.0.2.10'), ('5432/tcp', 'deny', 'Anywhere')])
        self.assertEqual(self.run_root('firewall.remove', {**allow, 'retain': False}, 'absent')[1], {'backend': 'absent'})
        self.assertNotIn('192.0.2.10', [row['source'] for row in json.loads(self.state.read_text())['rows']])

    def test_refusal_is_the_terminal_output_and_fails_the_run(self):
        self.state.write_text(json.dumps({'rows': [], 'active': False}))
        steps, terminal = self.run_root('firewall.allow', {'action': 'allow', 'port': '25', 'protocol': 'any', 'source': 'any'}, success=False)
        self.assertEqual((steps['apply']['status'], steps['apply']['outputs']['exitCode']), ('succeeded', 1))
        self.assertEqual(terminal, {'error_code': 'firewall.backend_inactive'})
        self.assertEqual(steps['accepted']['status'], 'failed')


if __name__ == '__main__':
    import unittest
    unittest.main()
