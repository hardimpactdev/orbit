"""Official SSH executes the actual Horizon DAG; scratch host and service-manager fixtures only."""
import hashlib
import importlib.util
import json
import os
import shutil
import sys
from pathlib import Path

import yaml

ROOT = Path(__file__).resolve().parents[4]
spec = importlib.util.spec_from_file_location('native_harness', ROOT / 'tests/native_harness.py')
assert spec is not None and spec.loader is not None
harness = importlib.util.module_from_spec(spec)
spec.loader.exec_module(harness)
NativeTestCase = harness.NativeTestCase


class HorizonInlineNative(NativeTestCase):
    def setUp(self):
        super().setUp()
        self.copy_behaviors('operations/horizon')
        official = self.repo / '.swamp/pulled-extensions/@swamp/ssh'
        official.parent.mkdir(parents=True)
        shutil.copytree(ROOT / '.swamp/pulled-extensions/@swamp/ssh', official)
        inventory = json.loads((ROOT / 'extensions/models/upstream_extensions.json').read_text())
        inventory_path = self.repo / 'extensions/models/upstream_extensions.json'
        inventory_path.parent.mkdir(parents=True)
        inventory_path.write_text(json.dumps({'@swamp/ssh': inventory['@swamp/ssh']}))
        tools = self.repo / 'tools'
        tools.mkdir()
        self.ssh = tools / 'ssh'
        self.ssh.write_text('#!' + sys.executable + '\n' + """import shlex,subprocess,sys
args=sys.argv[1:]
if '-M' in args or '-O' in args:raise SystemExit(0)
argv=shlex.split(args[-1])
if argv[:3]==['sudo','-n','--']:argv=argv[3:]
if argv[:4]==['/usr/bin/sudo','-n','--user','orbit']:argv=argv[5:]
r=subprocess.run(argv,input=sys.stdin.buffer.read(),capture_output=True)
sys.stdout.buffer.write(r.stdout);sys.stderr.buffer.write(r.stderr)
raise SystemExit(r.returncode)
""")
        self.ssh.chmod(0o755)
        self.scp = tools / 'scp'
        self.scp.write_text('#!' + sys.executable + '\n' + """import shutil,sys
from pathlib import Path
src=Path(sys.argv[-2]);dst=Path(sys.argv[-1].split(':',1)[1])
shutil.copytree(src,dst/src.name,dirs_exist_ok=True)
""")
        self.scp.chmod(0o755)
        self.native_env['PATH'] = str(tools) + ':' + os.environ['PATH']
        (self.repo / 'existing-key').write_text('fixture identity never consumed')
        self.host = self.repo / 'host'
        (self.host / 'etc/systemd/system').mkdir(parents=True)
        (self.host / 'etc/orbit').mkdir()
        self.manager = self.repo / 'systemctl-fixture'
        self.manager.write_text('#!/usr/bin/python3\nimport json,sys\nfrom pathlib import Path\np=Path('+repr(str(self.repo/'host-state.json'))+')\ns=json.loads(p.read_text())\ns["calls"].append(sys.argv[1:])\ncmd=sys.argv[1]\nif cmd=="start":s["active"]=True\np.write_text(json.dumps(s))\nif cmd=="is-active":\n print("active" if s["active"] else "inactive")\n sys.exit(0 if s["active"] else 3)\n')
        self.manager.chmod(0o755)
        self.analyzer = self.repo / 'analyzer-fixture'
        self.analyzer.write_text('#!/bin/sh\nexit 0\n')
        self.analyzer.chmod(0o755)
        self.php = self.repo / 'php-fixture'
        self.php.write_text('#!/usr/bin/python3\nfrom pathlib import Path\nPath('+repr(str(self.repo/'horizon-terminated'))+').write_text("terminated")\n')
        self.php.chmod(0o755)
        (self.repo/'host-state.json').write_text(json.dumps({'active':False,'calls':[]}))
        workflow = yaml.safe_load((ROOT/'workflows/workflow-operation-process-horizon-handoff.yaml').read_text())
        for job in workflow['jobs']:
            self.assertEqual(job.pop('target'), 'orbit-node-209')
            self.assertEqual(job.pop('labels'), {'node': '209'})
            for step in job['steps']:
                self.assertEqual(step.pop('target'), 'orbit-node-209')
                self.assertEqual(step.pop('labels'), {'node': '209'})
                if step['task']['behaviorType'] == '@swamp/ssh':
                    step['task']['globalArgs'].update(sshBinary=str(self.ssh), scpBinary=str(self.scp))
        text = yaml.safe_dump(workflow,sort_keys=False)
        text = text.replace('/etc/systemd/system',str(self.host/'etc/systemd/system')).replace('/etc/orbit',str(self.host/'etc/orbit'))
        text = text.replace('/usr/bin/systemctl',str(self.manager)).replace('/usr/bin/systemd-analyze',str(self.analyzer))
        text = text.replace('/tmp/orbit-horizon-',str(self.repo/'target-assets-'))
        (self.repo/'workflows/workflow-operation-process-horizon-handoff.yaml').write_text(text)
        for script in (self.repo/'extensions/behaviors/operations/horizon/scripts').glob('*.py'):
            script.write_text(script.read_text().replace('/etc/systemd/system',str(self.host/'etc/systemd/system')).replace('/etc/orbit',str(self.host/'etc/orbit')).replace('/usr/bin/systemctl',str(self.manager)))
        self.content='[Unit]\nX-Orbit-Process-ID=8\n[Service]\nExecStart=/usr/bin/true\n'
        self.inputs={'operation_id':17,'node_id':9,'kind':'process.horizon_handoff','execution_secrets':{},'intent':{
            'process_id':8,'unit':'orbit-process-8-horizon.service','content':self.content,'content_hash':hashlib.sha256(self.content.encode()).hexdigest(),
            'command':[str(self.php),'artisan','horizon'],'directory':str(self.repo),'user':'orbit','created':True,
            'ssh_execution':{'control_node_id':209,'identity_file':str(self.repo/'existing-key'),'public_key':'ssh-ed25519 public-only-fixture',
              'target':{'name':'target','host':'198.51.100.14','user':'orbit','port':22,
                'host_keys':['ssh-ed25519 AAAAC3NzaC1lZDI1NTE5AAAAIJ111111111111111111111111111111111111111111']}}}}
        self.unit=self.host/'etc/systemd/system/orbit-process-8-horizon.service'

    def run_handoff(self,success=True):
        self.cli('workflow','validate','operation-process-horizon-handoff')
        reply=self.cli('workflow','run','operation-process-horizon-handoff','--stdin',input=json.dumps(self.inputs),success=success)
        decoder=json.JSONDecoder()
        for n,char in enumerate(reply.stdout):
            if char=='{':
                try:
                    return decoder.raw_decode(reply.stdout[n:])[0]
                except ValueError:
                    pass
        self.fail('Native result not found')

    def test_missing_unit_is_published_enabled_and_started(self):
        run=self.run_handoff()
        self.assertEqual(run['status'],'succeeded')
        self.assertEqual(self.unit.read_text(),self.content)
        state=json.loads((self.repo/'host-state.json').read_text())
        self.assertIn(['daemon-reload'],state['calls'])
        self.assertIn(['start',self.inputs['intent']['unit']],state['calls'])

    def test_matching_active_unit_is_not_reloaded_or_restarted(self):
        self.unit.write_text(self.content)
        self.unit.chmod(0o644)
        (self.repo/'host-state.json').write_text(json.dumps({'active':True,'calls':[]}))
        run=self.run_handoff()
        self.assertEqual(run['status'],'succeeded')
        state=json.loads((self.repo/'host-state.json').read_text())
        self.assertNotIn(['daemon-reload'],state['calls'])
        self.assertFalse(any(c[0] in ['start','restart','stop'] for c in state['calls']))
        self.assertEqual((self.repo/'horizon-terminated').read_text(), 'terminated')

    def test_candidate_validation_failure_never_enables_or_publishes(self):
        self.analyzer.write_text('#!/bin/sh\nexit 1\n')
        self.analyzer.chmod(0o755)
        run=self.run_handoff(success=False)
        self.assertEqual(run['status'],'failed')
        self.assertFalse(self.unit.exists())
        calls=json.loads((self.repo/'host-state.json').read_text())['calls']
        self.assertTrue(all(call[0]=='is-active' for call in calls))

    def test_foreign_unit_is_refused_before_any_service_mutation(self):
        self.unit.write_text('[Unit]\nX-Orbit-Process-ID=99\n')
        self.unit.chmod(0o644)
        run=self.run_handoff(success=False)
        self.assertEqual(run['status'],'failed')
        self.assertEqual(json.loads((self.repo/'host-state.json').read_text())['calls'],[])
        self.assertIn('99',self.unit.read_text())

    def test_resume_reuses_copied_scripts_after_candidate_validation_failure(self):
        self.analyzer.write_text('#!/bin/sh\nexit 1\n')
        run = self.run_handoff(success=False)
        self.assertTrue((self.repo / ('target-assets-' + run['id']) / 'scripts/inspect.py').is_file())
        self.analyzer.write_text('#!/bin/sh\nexit 0\n')

        self.cli('workflow', 'resume', 'operation-process-horizon-handoff', '--run', run['id'], '--from', 'validate-unit')

        self.assertEqual(self.unit.read_text(), self.content)
        self.assertTrue(json.loads((self.repo / 'host-state.json').read_text())['active'])

    def test_official_argv_preserves_public_unit_bytes_without_shell_expansion(self):
        sentinel = self.repo / 'must-not-exist'
        self.content += "# public quote ' and $(touch " + str(sentinel) + ")\n"
        self.inputs['intent'].update(content=self.content, content_hash=hashlib.sha256(self.content.encode()).hexdigest())

        run = self.run_handoff()

        self.assertEqual(run['status'], 'succeeded')
        self.assertEqual(self.unit.read_text(), self.content)
        self.assertFalse(sentinel.exists())

    def test_successful_termination_does_not_require_an_active_unit_during_systemd_restart(self):
        self.unit.write_text(self.content)
        (self.repo / 'host-state.json').write_text(json.dumps({'active': True, 'calls': []}))
        self.php.write_text(
            '#!/usr/bin/python3\nimport json\nfrom pathlib import Path\np=Path('
            + repr(str(self.repo / 'host-state.json'))
            + ')\ns=json.loads(p.read_text())\ns["active"]=False\np.write_text(json.dumps(s))\n'
        )
        self.php.chmod(0o755)

        run = self.run_handoff()

        self.assertEqual(run['status'], 'succeeded')
        steps = {step['name']: step for step in run['jobs'][0]['steps']}
        self.assertEqual(steps['graceful-handoff']['status'], 'succeeded')
        self.assertEqual(steps['verify']['status'], 'skipped')
        history = json.loads(self.cli('workflow', 'history', 'get', run['id']).stdout)
        terminal = next(step for step in history['jobs'][0]['steps'] if step['name'] == 'result')
        result = json.loads(terminal['outputs']['stdout'])
        self.assertEqual(result['outcome'], 'terminated')
        self.assertEqual(result['state'], 'unknown')
