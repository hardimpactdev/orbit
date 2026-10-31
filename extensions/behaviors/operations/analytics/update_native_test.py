"""Native Analytics update replay and real HTTP/TLS secret scans."""
import hashlib
import json
import os
from pathlib import Path
import secrets
import shutil
import subprocess
import socket
import sys
import tempfile
import time
import unittest
import ssl
import threading
import http.server
import base64

ROOT=Path(__file__).resolve().parents[4]
sys.path.insert(0, str(ROOT / 'tests'))
from native_harness import NativeTestCase

class UpdateNative(NativeTestCase):
    native_timeout = 55
    def setUp(self):
        super().setUp()
        self.copy_behaviors('automatic-deploy','operations/_lib','operations/analytics')
        for name in ['processes-schedules/process-system.ts','processes-schedules/system.ts','node/system_command.ts']:
            source=ROOT/'extensions/behaviors/operations'/name
            destination=self.repo/'extensions/behaviors/operations'/name
            destination.parent.mkdir(parents=True,exist_ok=True);shutil.copyfile(source,destination)
        module=self.repo/'extensions/behaviors/operations/analytics/update.ts'
        module.write_text('import {http,invoke,initialize} from "./update_native_fixture.ts";\n'+module.read_text().replace('export const behavior = analyticsUpdate();','export const behavior = analyticsUpdate(() => http,invoke,initialize);'))
        source=ROOT/'workflows/workflow-operation-analytics-update-direct.yaml'
        (self.repo/'workflows'/source.name).write_text('\n'.join(line for line in source.read_text().splitlines() if not line.lstrip().startswith(('labels:','target:')))+'\n')
        self.secret='runtime-env-'+secrets.token_hex(24)
        self.secrets=(self.secret,base64.b64encode(self.secret.encode()).decode())
        self.environment={'SECRET_KEY_BASE':self.secret,'BASE_URL':'https://analytics.orbit'}
        raw=json.dumps(self.environment,sort_keys=True,separators=(',',':'))
        before={'process_id':250,'container':'orbit-process-250-plausible','desired':'running','spec':'a'*64,'image':'ghcr.io/plausible/community-edition:v3.2.1','command':['/entrypoint.sh','run'],'restart':'unless-stopped','working_directory':None,'ports':['10.44.0.3:8000:8000/tcp'],'mounts':[]}
        self.state={'operation':{'id':12,'kind':'analytics.update','node_id':209,'status':'queued','cancel_requested':False,'result':None,'input':{'process_id':250,'assignment_id':4,'runtime_environment':{'$secret':'analytics.runtime_environment','id':209},'version':'3.3.0','previous_version':'3.2.1','environment_sha256':hashlib.sha256(raw.encode()).hexdigest(),'health_url':'http://10.44.0.3:8000/api/health','before':before,'after':{**before,'spec':'b'*64,'image':'ghcr.io/plausible/community-edition:v3.3.0'}}},'calls':[]}
        if os.environ.get('ANALYTICS_UPDATE_DEV_DOCKER')=='1':
            self.prepare_dev_container(before)
        self.start_gateway()
        self.save()
    def start_gateway(self):
        # The private value stays in this test server's memory, never a Swamp argument/file.
        certificates=tempfile.TemporaryDirectory(prefix='analytics-update-tls-');self.addCleanup(certificates.cleanup)
        certificate=Path(certificates.name)/'ca.pem';key=Path(certificates.name)/'key.pem'
        subprocess.run(['openssl','req','-x509','-newkey','rsa:2048','-nodes','-keyout',str(key),'-out',str(certificate),'-days','1','-subj','/CN=127.0.0.1','-addext','subjectAltName=IP:127.0.0.1','-addext','basicConstraints=critical,CA:FALSE'],check=True,capture_output=True,timeout=15)
        key.chmod(0o600)
        owner=self
        self.private_reads=0
        self.public_requests=[]
        class Gateway(http.server.BaseHTTPRequestHandler):
            protocol_version='HTTP/1.1'
            def log_message(self,*args):pass
            def reply(self,status,body):
                encoded=json.dumps(body).encode();self.send_response(status);self.send_header('Content-Type','application/json');self.send_header('Content-Length',str(len(encoded)));self.send_header('Cache-Control','no-store');self.end_headers();self.wfile.write(encoded)
            def do_GET(self):
                state=owner.read()
                if '/private-material?' in self.path:
                    owner.private_reads+=1
                    if state.get('privateHttpError'):
                        self.reply(503,{'error':{'code':'test.private_unavailable','message':owner.secret}})
                    else:self.reply(200,{'data':{'runtime_environment':owner.environment}})
                else:self.reply(200,{'data':{**state['operation'],'input':{} if state['operation']['status']=='queued' else state['operation']['input']}})
            def do_POST(self):
                report=json.loads(self.rfile.read(int(self.headers['Content-Length'])))
                owner.public_requests.append(report)
                state=owner.read();state['operation']['status']=report['status'];state['operation']['result']=report.get('result');owner.state=state;owner.save()
                if state.get('loseProjection') and report['status']=='succeeded':
                    state['loseProjection']=False;owner.state=state;owner.save();self.close_connection=True;return
                self.reply(200,{'data':state['operation']})
        server=http.server.ThreadingHTTPServer(('127.0.0.1',0),Gateway)
        context=ssl.SSLContext(ssl.PROTOCOL_TLS_SERVER);context.load_cert_chain(str(certificate),str(key));server.socket=context.wrap_socket(server.socket,server_side=True)
        thread=threading.Thread(target=server.serve_forever,daemon=True);thread.start()
        self.addCleanup(lambda:(server.shutdown(),server.server_close(),thread.join(timeout=5)))
        self.state['gatewayUrl']=f'https://127.0.0.1:{server.server_port}'
        self.state['caFile']=str(certificate)
    def scan_scratch(self,*outputs):
        self.assert_private_absent(*outputs, json.dumps(self.public_requests))
    def prepare_dev_container(self,before):
        self.state['devDocker']=True
        plan=self.state['operation']['input']
        number=secrets.randbelow(10000000)+900000000
        name=f'orbit-process-{number}-plausible'
        with socket.socket() as listener:
            listener.bind(('127.0.0.1',0));port=listener.getsockname()[1]
        site=self.repo/'site';site.mkdir();(site/'index.html').write_text('ready')
        versions=[f'999.0.{number}',f'999.1.{number}']
        images=[f'ghcr.io/plausible/community-edition:v{version}' for version in versions]
        def docker(*args):
            return subprocess.run(['docker',*args],check=True,capture_output=True,text=True,timeout=30)
        image_source=self.repo/'image';image_source.mkdir()
        (image_source/'entrypoint.sh').write_text('#!/bin/sh\nset -e\nif [ "$1" = "db" ]; then exit 0; fi\nexec caddy file-server --listen :8000 --root /site\n')
        (image_source/'Dockerfile').write_text('FROM caddy:2.11\nCOPY --chmod=755 entrypoint.sh /entrypoint.sh\n')
        docker('build','--quiet','--tag',images[0],str(image_source))
        self.addCleanup(lambda:docker('image','rm',images[0]))
        docker('tag',images[0],images[1])
        self.addCleanup(lambda:docker('image','rm',images[1]))
        def cleanup():
            for suffix in ['', '-candidate', '-rollback-running', '-rollback-stopped', '-init-createdb', '-init-migrate']:
                subprocess.run(['docker','container','rm','--force','--volumes',name+suffix],capture_output=True,timeout=30)
        self.addCleanup(cleanup)
        before.update(process_id=number,container=name,image=images[0],command=['/entrypoint.sh','run'],ports=[f'127.0.0.1:{port}:8000/tcp'],mounts=[{'source':str(site),'target':'/site','read_only':True}])
        plan.update(process_id=number,previous_version=versions[0],version=versions[1],health_url=f'http://127.0.0.1:{port}/',before=before,after={**before,'spec':'b'*64,'image':images[1]})
        body={'Image':before['image'],'Cmd':before['command'],'Env':[key+'='+value for key,value in self.environment.items()],
              'Labels':{'orbit.managed':'true','orbit.container.kind':'process','orbit.process.id':str(number),'orbit.process.spec':'a'*64},
              'ExposedPorts':{'8000/tcp':{}},'HostConfig':{'PortBindings':{'8000/tcp':[{'HostIp':'127.0.0.1','HostPort':str(port)}]},
              'Mounts':[{'Type':'bind','Source':str(site),'Target':'/site','ReadOnly':True}]}}
        subprocess.run(['curl','-q','--fail','--silent','--unix-socket','/var/run/docker.sock','--request','POST','--header','Content-Type: application/json','--data-binary','@-',f'http://localhost/containers/create?name={name}'],input=json.dumps(body),capture_output=True,text=True,check=True,timeout=30)
        docker('container','start',name)
    def assert_dev_container(self,spec):
        if not self.state.get('devDocker'):return
        name=self.state['operation']['input']['before']['container']
        result=subprocess.run(['docker','container','inspect',name],capture_output=True,text=True,check=True,timeout=10)
        container=json.loads(result.stdout)[0]
        self.assertEqual(container['Config']['Labels']['orbit.process.spec'],spec)
        self.assertTrue(container['State']['Running'])
        plan=self.state['operation']['input']
        expected=plan['after'] if spec=='b'*64 else plan['before']
        self.assertEqual(container['Config']['Image'],expected['image'])
        self.assertIn('SECRET_KEY_BASE='+self.secret,container['Config']['Env'])
    def save(self): (self.repo/'update-native.json').write_text(json.dumps(self.state))
    def read(self): return json.loads((self.repo/'update-native.json').read_text())
    def workflow(self,success=True):
        started=time.monotonic()
        result=self.cli('workflow','run','operation-analytics-update-direct','--input','operation_id=12','--input','node_id=209','--input','kind=analytics.update',success=success)
        print(f'{self._testMethodName}: workflow {time.monotonic()-started:.3f}s',flush=True)
        return json.JSONDecoder().raw_decode(result.stdout.lstrip())[0]['id']
    def test_native_update_and_secret_redaction(self):
        run=self.workflow();state=self.read()
        self.assertEqual(state['operation']['status'],'succeeded')
        self.assertEqual(len(state['calls']),5 if state.get('devDocker') else 1)
        self.assert_dev_container('b'*64)
        history=self.cli('workflow','history','get',run).stdout
        self.assertEqual(self.private_reads,1)
        self.scan_scratch(history)
    def test_lost_projection_replays_without_repeating_effect(self):
        self.state['loseProjection']=True;self.save()
        run=self.workflow(success=False)
        self.cli('workflow','resume','operation-analytics-update-direct','--run',run,'--from','apply')
        self.assertEqual(len(self.read()['calls']),5 if self.state.get('devDocker') else 1)
        self.assertEqual(self.private_reads,1)
        self.scan_scratch()
    @unittest.skipUnless(os.environ.get('ANALYTICS_UPDATE_DEV_DOCKER')=='1','actual Docker replay')
    def test_replayed_effect_finishes_existing_replacement(self):
        self.workflow()
        self.state=self.read()
        self.state['operation']['status']='running'
        self.state['operation']['result']=None
        self.save()
        self.workflow()
        state=self.read()
        self.assertEqual(state['operation']['status'],'succeeded')
        self.assertEqual(len(state['calls']),6)
        self.assert_dev_container('b'*64)
        self.scan_scratch()

    def test_failed_health_projects_system_restoration(self):
        self.state['refuseHealth']=True;self.save();self.workflow(success=False)
        state=self.read();self.assertEqual(state['operation']['result'],{'error_code':'analytics.update_failed','rolled_back':True})
        self.assertEqual(len(state['calls']),5 if state.get('devDocker') else 1)
        self.assert_dev_container('a'*64)
        self.scan_scratch()

    def test_real_http_callback_failure_redacts_private_error(self):
        self.state['throwPrivate']=True;self.save();self.workflow(success=False)
        self.assertEqual(self.private_reads,1);self.scan_scratch()
    def test_real_http_private_refusal_redacts_response_body(self):
        self.state['privateHttpError']=True;self.save();self.workflow(success=False)
        self.assertEqual(self.private_reads,1);self.assertEqual(self.read()['calls'],[]);self.scan_scratch()
    def test_real_http_private_action_payload_never_becomes_a_result(self):
        self.state['privatePayload']=True;self.save();self.workflow()
        self.assertEqual(self.private_reads,1);self.scan_scratch()

if __name__=='__main__' :unittest.main()
