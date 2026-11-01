"""Run the shipped DAG with official SSH and a disposable UFW inventory seam."""
import json
import os
import sys
from pathlib import Path

import yaml

ROOT = Path(__file__).resolve().parents[4]
sys.path.insert(0, str(ROOT / 'tests'))
from native_harness import NativeTestCase

KEY = 'ssh-ed25519 AAAAC3NzaC1lZDI1NTE5AAAAIAEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEB'


class AllowlistNative(NativeTestCase):
    def setUp(self):
        super().setUp()
        self.copy_behaviors('operations/firewall')
        folder = self.repo / 'extensions/behaviors/operations/firewall'
        source = folder / 'mutation-result.ts'
        source.write_text(source.read_text().replace('${userInfo().homedir}/.ssh/known_hosts', './known_hosts'))
        (self.repo / 'known_hosts').write_text('10.44.0.9 ' + KEY + '\n')
        self.cli('extension', 'pull', '@swamp/ssh@2026.10.23.1')
        self.cli('extension', 'pull', '@swamp/cfgmgmt@2026.10.27.1')
        workflow = yaml.safe_load((ROOT / 'workflows/workflow-operation-firewall-direct.yaml').read_text())
        for step in workflow['jobs'][0]['steps']:
            self.assertEqual(step.pop('target'), 'orbit-node-209')
        (self.repo / 'workflows/workflow-operation-firewall-direct.yaml').write_text(yaml.safe_dump(workflow))
        self.state = self.repo / 'state.json'
        self.state.write_text(json.dumps({'rows': [{'to': 'Anywhere', 'source': '10.44.0.0/24', 'action': 'allow', 'comment': 'orbit:wireguard-members'}]}))
        fixture = self.repo / 'allowlist_fixture.py'
        fixture.write_text((ROOT / 'extensions/behaviors/operations/firewall/allowlist_test.py').read_text().split('class AllowlistTest')[0].replace("SCRIPT = Path(__file__).parent / 'scripts/allowlist.py'", "SCRIPT = Path(__file__).parent / 'extensions/behaviors/operations/firewall/scripts/allowlist.py'"))
        binary = self.repo / 'bin'
        binary.mkdir()
        ssh = binary / 'ssh'
        ssh.write_text('#!' + sys.executable + '\n' + '''import json,os,shlex,sys
from pathlib import Path
if any(x in sys.argv for x in ['-O','-N','-MNf','-M']):raise SystemExit(0)
command=sys.argv[-1]
if '__swamp_sudo_ok__' in command:
 print('__swamp_sudo_ok__')
 command=shlex.split(command)[-1].split('exec </dev/null; ',1)[-1]
args=shlex.split(command)
if '-c' not in args:raise SystemExit('unexpected command')
index=args.index('-c')
namespace={'__name__':'native_fixture'}
exec(args[index+1],namespace)
sys.path.insert(0,str(Path.cwd()))
from allowlist_fixture import Ufw
state=Path(os.environ['ALLOWLIST_STATE'])
ufw=Ufw(json.loads(state.read_text())['rows'])
namespace['command']=ufw.command
try: print(json.dumps(namespace['converge'](json.loads(args[index+2]))))
finally: state.write_text(json.dumps({'rows':ufw.rows,'effects':ufw.effects}))
''')
        ssh.chmod(0o755)
        self.native_env = {'PATH': str(binary) + os.pathsep + os.environ['PATH'], 'ALLOWLIST_STATE': str(self.state)}
        self.intent = {'ssh': {'identity_file': '/fixture/key', 'target': {'host': '10.44.0.9', 'user': 'orbit', 'port': 22, 'host_keys': [KEY]}},
                       'rule': {'node_id': 9, 'name': 'rex', 'action': 'allowlist', 'source': 'any', 'port': '7297', 'protocol': 'tcp', 'sources': ['10.44.0.6', '10.44.0.9']},
                       'tuples': [], 'legacy_remove': [], 'ensure': 'present'}

    def run_root(self):
        kind = 'firewall.remove' if self.intent['ensure'] == 'absent' else 'firewall.allowlist'
        result = self.cli('workflow', 'run', 'operation-firewall-direct', '--input', 'operation_id=17', '--input', 'node_id=9', '--input', 'kind=' + kind,
                          '--input', 'intent=' + json.dumps(self.intent), '--input', 'execution_secrets={}')
        history = json.loads(self.cli('workflow', 'history', 'get', json.loads(result.stdout)['id']).stdout)
        step = next(step for step in history['jobs'][0]['steps'] if step['name'] == 'result')
        self.assertEqual(json.loads(step['outputs']['stdout']), {'backend': 'absent' if self.intent['ensure'] == 'absent' else 'active'})
        return json.loads(self.state.read_text())

    def test_allow_replace_repeat_and_remove_through_the_native_root(self):
        self.cli('workflow', 'validate', 'operation-firewall-direct')
        first = self.run_root()
        self.assertEqual([row['action'] for row in first['rows'][:3]], ['allow', 'allow', 'deny'])
        repeat = self.run_root()
        self.assertEqual(repeat['effects'], [])
        self.intent['rule']['sources'] = ['10.44.0.16/28']
        changed = self.run_root()
        self.assertEqual(changed['rows'][0]['source'], '10.44.0.16/28')
        self.assertNotIn('10.44.0.6', [row['source'] for row in changed['rows']])
        self.intent['ensure'] = 'absent'
        removed = self.run_root()
        self.assertEqual(len(removed['rows']), 1)
        self.assertEqual(removed['rows'][0]['comment'], 'orbit:wireguard-members')


if __name__ == '__main__':
    import unittest
    unittest.main()
