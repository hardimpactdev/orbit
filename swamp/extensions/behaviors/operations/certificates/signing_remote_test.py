"""Real remote signing with disposable custody; no fleet connections or CA material output."""
import base64
import hashlib
import json
import os
from pathlib import Path
import shutil
import socket
import subprocess
import sys
import time
import unittest
import urllib.request
import uuid
import yaml

ROOT = Path(__file__).resolve().parents[4]
sys.path.insert(0, str(ROOT / 'tests'))
from native_harness import NativeTestCase


@unittest.skipUnless(os.environ.get('SWAMP_BIN'), 'requires the pinned native Swamp binary')
class RemoteSigningTest(NativeTestCase):
    def test_canonical_model_admits_only_its_declared_ca_to_the_remote_signer(self):
        self.native_timeout = 120
        self.native_env = {'SWAMP_HOME': str(self.repo / 'home'), 'SWAMP_CONFIG_DIR': os.environ.get('SWAMP_CONFIG_DIR', str(Path.home() / '.config/swamp')), 'SWAMP_API_KEY_FILE': os.environ.get('SWAMP_API_KEY_FILE', str(Path.home() / '.config/swamp/api-key'))}
        self.copy_behaviors('automatic-deploy', 'operations/_lib', 'operations/certificates')
        local = self.repo / 'extensions/behaviors/operations/routes'
        local.mkdir(parents=True)
        for filename in ('local-program.ts', 'caddy-program.ts'):
            shutil.copy(ROOT / 'extensions/behaviors/operations/routes' / filename, local)
        worker = self.repo / 'extensions/behaviors/node-worker'
        worker.mkdir(parents=True)
        shutil.copy(ROOT / 'extensions/behaviors/node-worker/command.ts', worker)
        module = self.repo / 'extensions/behaviors/operations/certificates/mod.ts'
        module.write_text('import { fixtureSign } from "./remote_fixture.ts";\n' + module.read_text().replace('...certificates()', '...certificates(undefined, undefined, fixtureSign)'))
        (module.parent / 'remote_fixture.ts').write_text('''import { signCertificateBatch } from "./signing.ts";
export const fixtureSign: typeof signCertificateBatch = (leaves, requests, custody, signal) =>
  signCertificateBatch(leaves, requests, custody, signal, async (program, input) => {
    const child = new Deno.Command("/usr/bin/python3", {args:["-I","-c",program],stdin:"piped",stdout:"piped",stderr:"null"}).spawn();
    const writer=child.stdin.getWriter();await writer.write(new TextEncoder().encode(JSON.stringify(input)));await writer.close();
    const result=await child.output();if(!result.success)throw new Error("Fixture signing process failed");
    return JSON.parse(new TextDecoder().decode(result.stdout));
  });
''')
        ca_key = subprocess.run(['openssl', 'genrsa', '4096'], capture_output=True, check=True).stdout
        ca = subprocess.run(['openssl', 'req', '-x509', '-key', '/dev/stdin', '-subj', '/CN=Disposable remote CA', '-days', '500', '-addext', 'basicConstraints=critical,CA:TRUE'], input=ca_key, capture_output=True, check=True).stdout
        leaf_key = subprocess.run(['openssl', 'genrsa', '2048'], capture_output=True, check=True).stdout
        csr = subprocess.run(['openssl', 'req', '-new', '-key', '/dev/stdin', '-subj', '/CN=private.test'], input=leaf_key, capture_output=True, check=True).stdout
        self.secrets = (ca_key.decode(), leaf_key.decode())
        self.cli('vault', 'create', 'local_encryption', 'disposable-ca')
        self.cli('vault', 'put', 'disposable-ca', 'authority', input=json.dumps({'certificate': ca.decode(), 'private_key': ca_key.decode()}))
        model = yaml.safe_load((ROOT / 'models/@nckrtl/certificates/orbit-certificate-signing.yaml').read_text())
        model.update(name='remote-certificate-signer', id=str(uuid.uuid4()))
        args = model['globalArguments']
        args.update(vaultName='disposable-ca', secretKey='authority')
        if 'caAuthority' in args:
            args['caAuthority'] = "${{ vault.get('disposable-ca', 'authority') }}"
        models = self.repo / 'models/@nckrtl/certificates'
        models.mkdir(parents=True)
        (models / 'remote.yaml').write_text(yaml.safe_dump(model, sort_keys=False))
        missing=json.loads(json.dumps(model));missing.update(name='missing-certificate-reference',id=str(uuid.uuid4()));missing['globalArguments'].pop('caAuthority',None)
        (models/'missing.yaml').write_text(yaml.safe_dump(missing,sort_keys=False))
        leaf = {'node_id':9, 'scope':'route-7-router', 'user':'orbit', 'domain':'private.test', 'version':'b'*16, 'root_hash':hashlib.sha256(ca).hexdigest(), 'agent_sha256':'a'*64}
        inputs = {'operation_id':19, 'node_id':9, 'kind':'certificate.converge', 'run_id':'${{ run.id }}', 'leaf':leaf, 'request':{'state':'request', 'request_base64':base64.b64encode(csr).decode()}}
        workflow = {'id':str(uuid.uuid4()), 'name':'remote-certificate-signing', 'vaults':['disposable-ca'], 'jobs':[{'name':'certificate', 'steps':[{'name':'sign', 'target':'fixture-control', 'task':{'type':'behavior', 'behaviorType':'@nckrtl/certificates', 'behaviorName':'remote-certificate-signer', 'methodName':'sign', 'inputs':inputs}}]}]}
        (self.repo / 'workflows/workflow-remote-signing.yaml').write_text(yaml.safe_dump(workflow, sort_keys=False))
        missing_workflow=json.loads(json.dumps(workflow));missing_workflow.update(id=str(uuid.uuid4()),name='missing-certificate-reference');missing_workflow['jobs'][0]['steps'][0]['task']['behaviorName']='missing-certificate-reference'
        (self.repo/'workflows/workflow-missing-signing.yaml').write_text(yaml.safe_dump(missing_workflow,sort_keys=False))
        self.cli('model', 'type', 'describe', '@nckrtl/certificates')
        self.cli('access', 'token', 'mint', 'fixture-admin', '--principal', 'user:fixture')
        def decode(raw):
            remaining=raw.lstrip();values=[]
            while remaining:
                value,end=json.JSONDecoder().raw_decode(remaining);values.append(value);remaining=remaining[end:].lstrip()
            return values[-1]
        def secret_file(name, value):
            path=self.repo/name;path.write_text(value);path.chmod(0o600);return str(path)
        admin = secret_file('admin-token', decode(self.cli('access', 'token', 'reveal', 'fixture-admin', '--yes').stdout)['token'])
        with socket.socket() as sock:
            sock.bind(('127.0.0.1', 0));port=sock.getsockname()[1]
        url=f'ws://127.0.0.1:{port}';flags=('--server',url,'--token-file',admin)
        env={k:v for k,v in os.environ.items() if not k.startswith('SWAMP_')};env.update(self.native_env,SWAMP_NO_UPDATE_CHECK='1',SWAMP_NO_TELEMETRY='1')
        processes=[]
        serve_log=(self.repo/'serve.log').open('w+')
        try:
            serve=subprocess.Popen([os.environ['SWAMP_BIN'],'serve','--host','127.0.0.1','--port',str(port),'--auth-mode','token','--admins','user:fixture','--no-schedule','--remote-only','--dispatch-env-allow=,'],cwd=self.repo,env=env,stdout=serve_log,stderr=serve_log);processes.append(serve)
            deadline=time.monotonic()+30
            while True:
                try:urllib.request.urlopen(f'http://127.0.0.1:{port}/ready',timeout=1).close();break
                except OSError:
                    
                    if serve.poll() is not None:
                        serve_log.seek(0);self.assert_private_absent();self.fail(serve_log.read()[-4000:])
                    self.assertLess(time.monotonic(),deadline,'scratch serve readiness');time.sleep(.1)
            enrollment=secret_file('worker-enrollment',decode(self.cli('worker','token','create','fixture-control','--duration','30d',*flags).stdout)['token'])
            self.cli('access','token','mint','fixture-control-access','--principal','worker:fixture-control',*flags)
            access=secret_file('worker-access',decode(self.cli('access','token','reveal','fixture-control-access','--yes').stdout)['token'])
            worker_env=dict(env,SWAMP_CONFIG_DIR=str(self.repo/'worker-config'));worker_env.pop('SWAMP_API_KEY_FILE',None)
            connected=subprocess.Popen([os.environ['SWAMP_BIN'],'worker','connect',url,'--token-file',enrollment,'--server-token-file',access,'--cache-dir',str(self.repo/'worker-cache')],cwd=self.repo,env=worker_env,stdout=subprocess.DEVNULL,stderr=subprocess.DEVNULL);processes.append(connected)
            deadline=time.monotonic()+30
            while True:
                roster=decode(self.cli('worker','list',*flags).stdout)['workers']
                if any(w.get('tokenName')=='fixture-control' and w.get('status')=='idle' for w in roster):break
                self.assertIsNone(connected.poll());self.assertLess(time.monotonic(),deadline,'scratch worker readiness');time.sleep(.1)
            refused=decode(self.cli('workflow','run','missing-certificate-reference',*flags,success=False).stdout)
            self.assertEqual('failed',refused['status'])
            self.assertEqual('app-dev.certificate_sign_failed',refused['jobs'][0]['steps'][0]['error'])
            result=decode(self.cli('workflow','run','remote-certificate-signing',*flags).stdout)
            self.assertEqual('succeeded',result['status'])
            # No private keys reach resources, history, logs, model definitions or worker caches.
            self.assert_private_absent(json.dumps(result))
        finally:
            for process in reversed(processes):
                if process.poll() is None:
                    process.terminate()
                    try:process.wait(timeout=10)
                    except subprocess.TimeoutExpired:process.kill();process.wait(timeout=5)
            serve_log.close()
            # Test credentials are disposable and separate from certificate key material.
            self.assert_private_absent()


if __name__ == '__main__':
    unittest.main()
