"""Native credential DAG, real HTTP effects and official SSH schemas; no fleet."""

import base64
import copy
import hashlib
import json
import shutil
import sys
import threading
from http.server import BaseHTTPRequestHandler, ThreadingHTTPServer
from pathlib import Path

import yaml

ROOT = Path(__file__).resolve().parents[1]
sys.path.insert(0, str(ROOT))
from tests.native_harness import NativeTestCase


class MetricsCredentialsNative(NativeTestCase):
    native_timeout = 120

    def setUp(self):
        super().setUp()
        self.active = 'fixture-active-' + self.repo.name
        self.pending = 'fixture-pending-' + self.repo.name
        self.secret = json.dumps({'active_password': self.active, 'pending_password': self.pending})
        self.secrets = (self.active, self.pending, self.secret)
        self.current = self.active
        self.applied = 0
        self.fail_apply = False
        self.fail_confirm = False
        owner = self

        class Handler(BaseHTTPRequestHandler):
            def log_message(self, *_args):
                pass

            def authorized(self):
                return self.headers.get('Authorization') == 'Basic ' + base64.b64encode(('admin:' + owner.current).encode()).decode()

            def finish_reply(self, status):
                self.send_response(status)
                self.end_headers()
                self.wfile.write(b'{"public":true}')

            def do_GET(self):
                status = 200 if self.authorized() else 401
                if owner.fail_confirm and owner.applied and status == 200:
                    status = 503
                self.finish_reply(status)

            def do_PUT(self):
                value = json.loads(self.rfile.read(int(self.headers['Content-Length'])))
                if owner.fail_apply:
                    self.finish_reply(503)
                elif self.authorized() and value['oldPassword'] == owner.current and value['newPassword'] == owner.pending:
                    owner.current = owner.pending
                    owner.applied += 1
                    self.finish_reply(200)
                else:
                    self.finish_reply(401)

        self.http = ThreadingHTTPServer(('127.0.0.1', 0), Handler)
        self.thread = threading.Thread(target=self.http.serve_forever, daemon=True)
        self.thread.start()
        self.addCleanup(self.http.server_close)
        self.addCleanup(self.http.shutdown)
        self.copy_behaviors('operations/metrics-credentials')
        script = self.repo / 'extensions/behaviors/operations/metrics-credentials/scripts/credential.py'
        script.write_text(script.read_text().replace('HTTPConnection(address, 3000)', f'HTTPConnection(address, {self.http.server_port})'))
        self.state = self.repo / 'calls.json'
        self.state.write_text('[]')
        schema = (ROOT / '.swamp/pulled-extensions/@swamp/ssh/models/_lib/schemas.ts').as_uri()
        fixture = '''import { z } from "npm:zod@4.4.3";
import { ExecArgsSchema, GlobalArgsSchema } from SCHEMA;
export const behavior = {
 type: "@fixture/metrics-credential-host", version: "2026.11.01.1",
 globalArguments: z.record(z.string(), z.unknown()),
 resources: { output: { schema: z.object({stdout:z.string(),exitCode:z.number()}), lifetime:"infinite", garbageCollection:10 } },
 methods: { exec: { description:"Real HTTP action through synthetic SSH host", arguments:z.record(z.string(),z.unknown()), execute: async (raw,ctx) => {
  const a=ExecArgsSchema.parse(raw); GlobalArgsSchema.parse(ctx.globalArgs);
  const calls=JSON.parse(Deno.readTextFileSync(STATE)); calls.push(a.argv[3]); Deno.writeTextFileSync(STATE,JSON.stringify(calls));
  const p=new Deno.Command(a.argv[0],{args:a.argv.slice(1),stdin:"piped",stdout:"piped",stderr:"piped"}).spawn();
  const w=p.stdin.getWriter(); await w.write(new TextEncoder().encode(a.stdin)); await w.close();
  const r=await p.output(); const stdout=new TextDecoder().decode(r.stdout);
  if(r.code!==0) throw new Error(new TextDecoder().decode(r.stderr));
  return {dataHandles:[await ctx.writeResource("output","result",{stdout,exitCode:r.code})]};
 } } }
};'''.replace('SCHEMA', json.dumps(schema)).replace('STATE', json.dumps(str(self.state)))
        (self.repo / 'extensions/behaviors/fixture.ts').write_text(fixture)
        workflow = yaml.safe_load((ROOT / 'workflows/workflow-operation-metrics-credentials-reset.yaml').read_text())
        for step in workflow['jobs'][0]['steps']:
            self.assertEqual(step.pop('target'), 'orbit-node-209')
            step['task'] = copy.deepcopy(step['task'])
            if step['task'].get('behaviorType') == '@swamp/ssh':
                step['task']['behaviorType'] = '@fixture/metrics-credential-host'
        (self.repo / 'workflows/workflow-credentials.yaml').write_text(yaml.safe_dump(workflow, sort_keys=False))
        self.cli('vault', 'create', 'local_encryption', 'orbit-control-inputs')
        self.cli('vault', 'put', 'orbit-control-inputs', 'metrics-credentials-operation-12', input=self.secret)

    def run_reset(self, success=True):
        ssh = {'identity_file':'/approved', 'target':{'host':'127.0.0.1','user':'orbit','port':22,'host_keys':['ssh-ed25519 AAAAC3NzaC1lZDI1NTE5AAAAINqVuK+N4gL/sDvGNKnGrYhEtEFnZx9hvH+134PsQgND']}}
        inputs = {'operation_id':12,'node_id':9,'kind':'metrics.credentials_reset','intent':{'ssh_execution':ssh,'address':'127.0.0.1','pending_fingerprint':hashlib.sha256(self.pending.encode()).hexdigest()},'execution_secrets':{'credentials':{'vault':'orbit-control-inputs','key':'metrics-credentials-operation-12'}}}
        started = json.loads(self.cli('workflow','run','operation-metrics-credentials-reset','--input',json.dumps(inputs),success=success).stdout)
        return json.loads(self.cli('workflow','history','get',started['id']).stdout)

    def test_changes_and_verifies_without_public_secret_bytes(self):
        history = self.run_reset()
        self.assertEqual(history['status'], 'succeeded')
        self.assertEqual(self.current, self.pending)
        self.assertEqual(self.applied, 1)
        self.assertEqual(json.loads(self.state.read_text()), ['probe','apply','confirm'])

    def test_already_applied_candidate_skips_the_write(self):
        self.current = self.pending
        self.assertEqual(self.run_reset()['status'], 'succeeded')
        self.assertEqual(self.applied, 0)
        self.assertEqual(json.loads(self.state.read_text()), ['probe','confirm'])

    def test_refused_write_has_no_confirmation_or_false_success(self):
        self.fail_apply = True
        self.assertEqual(self.run_reset(False)['status'], 'failed')
        self.assertEqual(self.current, self.active)
        self.assertEqual(json.loads(self.state.read_text()), ['probe','apply'])

    def test_fresh_run_after_uncertain_confirmation_observes_the_same_pending_password(self):
        self.fail_confirm = True
        self.assertEqual(self.run_reset(False)['status'], 'failed')
        self.assertEqual(self.applied, 1)
        self.fail_confirm = False
        self.assertEqual(self.run_reset()['status'], 'succeeded')
        self.assertEqual(self.applied, 1)
