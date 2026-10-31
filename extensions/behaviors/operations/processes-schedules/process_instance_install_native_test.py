"""Actual same-root placed member DAGs, using the shared local native harness."""
import hashlib
import json
from pathlib import Path
import sys
import uuid
ROOT = Path(__file__).resolve().parents[4]
sys.path.insert(0, str(ROOT / 'tests'))
from native_harness import NativeTestCase


sys.path.insert(0, str(Path(__file__).parent))
from instance_native_support import InstanceRuntimeCase

class RuntimeMembers(InstanceRuntimeCase):
    def test_process_definitions_reuse_private_canonical_installer(self):
        self.state['operation']['input']['members'] = [self.process(7, True), self.process(11, True)]
        state = self.execute('process.install_instance_definitions')
        self.assertEqual(state['operation']['status'], 'succeeded')
        self.assertEqual([c['name'] for c in state['commands']], ['process.converge-container', 'process.status'] * 2)
        self.assertEqual(sum('/private-material?' in c['url'] for c in state['calls']), 2)


    def test_process_definitions_preserve_stopped_intent(self):
        member = self.process(7, True)
        member['input']['installation']['desired'] = 'stopped'
        self.state['operation']['input']['members'] = [member]
        state = self.execute('process.install_instance_definitions')
        self.assertEqual(state['operation']['status'], 'succeeded')
        self.assertEqual(state['commands'][0], {'name': 'process.converge-container', 'id': 7, 'desired': 'stopped'})
        self.assertEqual(state['operation']['result']['members'][0]['state'], 'inactive')
        self.assertFalse(any(command['name'] == 'process.control' for command in state['commands']))


    def test_cancelled_root_has_no_effect_or_private_fetch(self):
        self.state['operation']['cancel_requested'] = True
        self.state['operation']['input']['members'] = [self.process(7, True)]
        state = self.execute('process.install_instance_definitions')
        self.assertEqual(state['commands'], [])
        self.assertFalse(any('/private-material?' in c['url'] for c in state['calls']))
        self.assertEqual(state['operation']['status'], 'cancelled')


