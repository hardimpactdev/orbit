"""Actual same-root placed member DAGs, using the shared local native harness."""
import hashlib
import json
from pathlib import Path
import sys
import uuid
ROOT = Path(__file__).resolve().parents[4]
sys.path.insert(0, str(ROOT / 'tests'))
from native_harness import NativeTestCase


class InstanceRuntimeCase(NativeTestCase):
    def setUp(self):
        super().setUp()
        self.copy_behaviors('automatic-deploy', 'operations/_lib', 'operations/processes-schedules', 'operations/routes')
        transport = self.repo/'extensions/behaviors/operations/node/system_command.ts'
        transport.parent.mkdir(parents=True, exist_ok=True)
        transport.write_text((ROOT/'extensions/behaviors/operations/node/system_command.ts').read_text())
        self.secret = uuid.uuid4().hex
        self.secrets = (self.secret,)
        self.native_env = {'INSTANCE_RUNTIME_SECRET': self.secret}
        base = self.repo / 'extensions/behaviors/operations/processes-schedules'
        source = base / 'instance.ts'
        source.write_text('import {http,invoke} from "./instance_native_fixture.ts";\n' + source.read_text().replace('export const behavior = instanceRuntime();', 'export const behavior = instanceRuntime(() => http, invoke, (name, input, signal) => invoke(name, input, "", signal));'))
        source = self.repo / 'extensions/behaviors/operations/failure.ts'
        source.write_text('import {http} from "./processes-schedules/instance_native_fixture.ts";\n' + (ROOT/'extensions/behaviors/operations/failure.ts').read_text().replace('export const behavior = nativeFailure();', 'export const behavior = nativeFailure(() => http);'))
        source = self.repo / 'extensions/behaviors/operations/routes/publication.ts'
        source.write_text('import {http,caddy} from "../processes-schedules/instance_native_fixture.ts";\n' + source.read_text().replace('export const behavior = publication();', 'export const behavior = publication(() => http, caddy);'))
        for source in (ROOT / 'workflows').glob('workflow-operation-*-instance*.yaml'):
            (self.repo / 'workflows' / source.name).write_text('\n'.join(line for line in source.read_text().splitlines() if not line.lstrip().startswith(('target:', 'labels:'))) + '\n')
        self.state = {'calls': [], 'commands': [], 'operation': {'id': 17, 'kind': 'process.restart_instance', 'node_id': 9, 'status': 'queued', 'cancel_requested': False, 'result': None, 'input': {'instance_id': 8, 'members': []}}}


    def process(self, process_id, install=False):
        control = {'process_id': process_id, 'agent_sha256': 'a'*64, 'runtime': 'docker', 'name': f'orbit-process-{process_id}-proof', 'on_demand': False, 'vite': None}
        installation = {key: value for key, value in control.items() if key != 'vite'}
        installation.update(desired='running', retired=None, spec='b'*64)
        return {'id': process_id, 'input': {'control': control, 'installation': installation} if install else control}


    def execute(self, kind=None, success=True):
        if kind:
            self.state['operation']['kind'] = kind
        (self.repo / 'instance-native.json').write_text(json.dumps(self.state))
        name = 'operation-' + self.state['operation']['kind'].replace('.', '-').replace('_', '-')
        self.cli('workflow', 'validate', name)
        output = self.cli('workflow', 'run', name, '--input', 'operation_id=17', '--input', 'node_id=9', '--input', 'kind=' + self.state['operation']['kind'], success=success)
        self.run = json.JSONDecoder().raw_decode(output.stdout.lstrip())[0]
        return json.loads((self.repo / 'instance-native.json').read_text())


