"""Native analytics update replay, secret persistence and Node209 health canary."""
import hashlib
import json
import os
from pathlib import Path
import secrets
import shutil
import subprocess
import socket
import tempfile
import time
import unittest
import ssl
import threading
import http.server
import base64

ROOT=Path(__file__).resolve().parents[4]
SWAMP=os.environ.get('SWAMP_BIN','swamp')

class UpdateNative(unittest.TestCase):
    def setUp(self):
        self.temp=tempfile.TemporaryDirectory(prefix='analytics-update-native-');self.addCleanup(self.temp.cleanup)
        self.repo=Path(self.temp.name)
        shutil.copy(ROOT/'.swamp.yaml',self.repo)
        for family in ['automatic-deploy','operation-runner','operations/_lib','operations/analytics']:
            shutil.copytree(ROOT/'extensions/behaviors'/family,self.repo/'extensions/behaviors'/family)
        module=self.repo/'extensions/behaviors/operations/analytics/update.ts'
        module.write_text('import {http,invoke} from "./update_native_fixture.ts";\n'+module.read_text().replace('export const behavior = analyticsUpdate();','export const behavior = analyticsUpdate(() => http,invoke);'))
        source=ROOT/'workflows/workflow-operation-analytics-update-direct.yaml'
        (self.repo/'workflows').mkdir()
        (self.repo/'workflows'/source.name).write_text('\n'.join(line for line in source.read_text().splitlines() if not line.lstrip().startswith(('labels:','target:')))+'\n')
        self.secret='runtime-env-'+secrets.token_hex(24)
        self.environment={'SECRET_KEY_BASE':self.secret,'BASE_URL':'https://analytics.orbit'}
        raw=json.dumps(self.environment,sort_keys=True,separators=(',',':'))
        before={'process_id':250,'container':'orbit-process-250-plausible','desired':'running','spec':'a'*64,'image':'ghcr.io/plausible/community-edition:v3.2.1','command':['/usr/local/bin/orbit-agent','service','plausible'],'restart':'unless-stopped','working_directory':None,'ports':['10.44.0.3:8000:8000/tcp'],'mounts':[]}
        self.state={'operation':{'id':12,'kind':'analytics.update','node_id':209,'status':'queued','cancel_requested':False,'result':None,'input':{'process_id':250,'assignment_id':4,'runtime_environment':{'$secret':'analytics.runtime_environment','id':209},'version':'3.3.0','previous_version':'3.2.1','agent_sha256':'a'*64,'environment_sha256':hashlib.sha256(raw.encode()).hexdigest(),'health_url':'http://10.44.0.3:8000/api/health','before':before,'after':{**before,'spec':'b'*64,'image':'ghcr.io/plausible/community-edition:v3.3.0'}}},'calls':[]}
        if os.environ.get('ANALYTICS_UPDATE_LIVE_HEALTH')=='1':
            self.state['liveHealth']=True
            self.state['operation']['input']['agent_sha256']=hashlib.sha256(Path('/usr/local/bin/orbit-agent').read_bytes()).hexdigest()
            self.state['operation']['input']['version']='3.2.1'
            self.state['operation']['input']['after']=before
        if os.environ.get('ANALYTICS_UPDATE_DEV_AGENT'):
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
        needles=[self.secret.encode(),base64.b64encode(self.secret.encode())]
        for output in outputs:
            self.assertFalse(any(needle in output.encode() for needle in needles),'private bytes in CLI output')
        self.assertFalse(self.secret in json.dumps(self.public_requests),'private bytes in public projection')
        for path in self.repo.rglob('*'):
            if not path.is_file() or path.is_symlink():continue
            with path.open('rb') as source:
                tail=b''
                while block:=source.read(1024*1024):
                    chunk=tail+block
                    self.assertFalse(any(needle in chunk for needle in needles),str(path))
                    tail=chunk[-max(map(len,needles)):]
    def prepare_dev_container(self,before):
        agent=str(Path(os.environ['ANALYTICS_UPDATE_DEV_AGENT']).resolve())
        self.state['devAgent']=agent
        plan=self.state['operation']['input']
        plan['agent_sha256']=hashlib.sha256(Path(agent).read_bytes()).hexdigest()
        number=secrets.randbelow(10000000)+900000000
        name=f'orbit-process-{number}-plausible'
        with socket.socket() as listener:
            listener.bind(('127.0.0.1',0));port=listener.getsockname()[1]
        site=self.repo/'site';site.mkdir();(site/'index.html').write_text('ready')
        versions=[f'999.0.{number}',f'999.1.{number}']
        images=[f'ghcr.io/plausible/community-edition:v{version}' for version in versions]
        def docker(*args):
            return subprocess.run(['docker',*args],check=True,capture_output=True,text=True,timeout=30)
        for image in images:
            docker('tag','caddy:2.11',image)
            self.addCleanup(lambda image=image: docker('image','rm',image))
        def cleanup():
            for suffix in ['', '-candidate', '-rollback-running', '-rollback-stopped']:
                subprocess.run(['docker','container','rm','--force',name+suffix],capture_output=True,timeout=30)
        self.addCleanup(cleanup)
        before.update(process_id=number,container=name,image=images[0],command=['caddy','file-server','--listen',':8000','--root','/site'],ports=[f'127.0.0.1:{port}:8000/tcp'],mounts=[{'source':str(site),'target':'/site','read_only':True}])
        plan.update(process_id=number,previous_version=versions[0],version=versions[1],health_url=f'http://127.0.0.1:{port}/',before=before,after={**before,'spec':'b'*64,'image':images[1]})
        envelope={'agent_sha256':plan['agent_sha256'],'deadline_ms':900000,'input':{**before,'environment':self.environment,'health':{'url':plan['health_url'],'attempts':5,'interval_seconds':1}}}
        result=subprocess.run(['sudo','-n','--',agent,'command','process.converge-container'],input=json.dumps(envelope),capture_output=True,text=True,timeout=55)
        self.assertTrue(json.loads(result.stdout)['ok'],result.stdout+result.stderr)
    def assert_dev_container(self,spec):
        if not self.state.get('devAgent'):return
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
    def cli(self,*args,success=True,input=None):
        env=dict(os.environ,SWAMP_NO_UPDATE_CHECK='1',SWAMP_NO_TELEMETRY='1')
        for key in ['SWAMP_SERVER_URL','SWAMP_SERVE_URL','SWAMP_SERVER_TOKEN','SWAMP_SERVER_TOKEN_FILE','SWAMP_REPO_DIR','SWAMP_CA_CERT']:env.pop(key,None)
        result=subprocess.run([SWAMP,*args,'--json'],cwd=self.repo,env=env,input=input,text=True,capture_output=True,timeout=55)
        for needle in [self.secret,base64.b64encode(self.secret.encode()).decode()]:
            self.assertFalse(needle in result.stdout or needle in result.stderr,'private bytes in CLI stdout/stderr')
        self.assertEqual(result.returncode==0,success,result.stdout+result.stderr)
        return result
    def workflow(self,success=True):
        started=time.monotonic()
        result=self.cli('workflow','run','operation-analytics-update-direct','--input','operation_id=12','--input','node_id=209','--input','kind=analytics.update',success=success)
        print(f'{self._testMethodName}: workflow {time.monotonic()-started:.3f}s',flush=True)
        return json.JSONDecoder().raw_decode(result.stdout.lstrip())[0]['id']
    def test_native_update_and_secret_redaction(self):
        run=self.workflow();state=self.read()
        self.assertEqual(state['operation']['status'],'succeeded')
        self.assertEqual(len(state['calls']),1)
        self.assert_dev_container('b'*64)
        history=self.cli('workflow','history','get',run).stdout
        self.assertEqual(self.private_reads,1)
        self.scan_scratch(history)
    def test_lost_projection_replays_without_repeating_effect(self):
        self.state['loseProjection']=True;self.save()
        run=self.workflow(success=False)
        self.cli('workflow','resume','operation-analytics-update-direct','--run',run,'--from','apply')
        self.assertEqual(len(self.read()['calls']),1)
        self.assertEqual(self.private_reads,1)
        self.scan_scratch()
    def test_failed_health_projects_agent_restoration(self):
        self.state['refuseHealth']=True;self.save();self.workflow(success=False)
        state=self.read();self.assertEqual(state['operation']['result'],{'error_code':'analytics.update_failed','rolled_back':True})
        self.assertEqual(len(state['calls']),1)
        self.assert_dev_container('a'*64)
        self.scan_scratch()

    def test_real_http_callback_failure_redacts_private_error(self):
        self.state['throwPrivate']=True;self.save();self.workflow(success=False)
        self.assertEqual(self.private_reads,1);self.scan_scratch()
    def test_real_http_private_refusal_redacts_response_body(self):
        self.state['privateHttpError']=True;self.save();self.workflow(success=False)
        self.assertEqual(self.private_reads,1);self.assertEqual(self.read()['calls'],[]);self.scan_scratch()
    def test_real_http_private_agent_payload_never_becomes_a_result(self):
        self.state['privatePayload']=True;self.save();self.workflow()
        self.assertEqual(self.private_reads,1);self.scan_scratch()

if __name__=='__main__' :unittest.main()
