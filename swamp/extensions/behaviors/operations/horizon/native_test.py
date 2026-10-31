"""Actual native inline DAG and filesystem publication, with service-manager effects faked."""
import hashlib
import importlib.util
import json
import os
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
        text = (ROOT/'workflows/workflow-operation-process-horizon-handoff.yaml').read_text()
        text = text.replace('/etc/systemd/system',str(self.host/'etc/systemd/system')).replace('/etc/orbit',str(self.host/'etc/orbit'))
        text = text.replace('/usr/bin/systemctl',str(self.manager)).replace('/usr/bin/systemd-analyze',str(self.analyzer))
        text = text.replace('sudo -n /usr/bin/env','/usr/bin/env').replace('sudo -n --user "$RUN_USER" -- ','').replace('sudo -n ','')
        text = text.replace('info.st_uid != 0','info.st_uid != os.getuid()').replace('info.st_gid != 0','info.st_gid != os.getgid()')
        workflow=yaml.safe_load(text)
        for job in workflow['jobs']:
            job.pop('target',None)
            job.pop('labels',None)
            for step in job['steps']:
                step.pop('target',None)
                step.pop('labels',None)
        (self.repo/'workflows/workflow-operation-process-horizon-handoff.yaml').write_text(yaml.safe_dump(workflow,sort_keys=False))
        self.content='[Unit]\nX-Orbit-Process-ID=8\n[Service]\nExecStart=/usr/bin/true\n'
        self.inputs={'operation_id':17,'node_id':9,'kind':'process.horizon_handoff','execution_secrets':{},'intent':{
            'process_id':8,'unit':'orbit-process-8-horizon.service','content':self.content,'content_hash':hashlib.sha256(self.content.encode()).hexdigest(),
            'command':[str(self.php),'artisan','horizon'],'directory':str(self.repo),'user':'orbit','created':True}}
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

    def test_hardlinked_owned_unit_is_refused_before_mutation(self):
        self.unit.write_text(self.content)
        self.unit.chmod(0o644)
        os.link(self.unit, self.host / 'unit-other-link')
        run = self.run_handoff(success=False)
        self.assertEqual(run['status'], 'failed')
        self.assertEqual(json.loads((self.repo/'host-state.json').read_text())['calls'], [])
