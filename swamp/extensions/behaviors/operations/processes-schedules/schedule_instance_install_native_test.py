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
    def test_schedule_definitions_reuse_private_canonical_installer(self):
        command = '/usr/bin/printf ' + self.secret
        ident = str(uuid.uuid4())
        self.state['operation']['input']['members'] = [{'id': ident, 'input': {'id': ident, 'command_hash': hashlib.sha256(command.encode()).hexdigest(), 'user': 'orbit', 'group': 'orbit', 'home': '/home/orbit', 'working_directory': '/srv/proof', 'shell': '/bin/bash', 'login_shell': False, 'calendar': 'daily', 'timeout_seconds': 60, 'desired': 'disabled'}}]
        state = self.execute('schedule.install_instance_definitions')
        self.assertEqual(state['operation']['status'], 'succeeded')
        self.assertEqual(state['commands'], [{'name': 'schedule.install', 'id': ident, 'desired': 'disabled'}])


