import json,os,pathlib,shutil,socket,subprocess,tempfile,time,urllib.request,uuid,unittest
REPO=pathlib.Path(__file__).resolve().parents[1]
CLI=os.environ.get('SWAMP_BIN','swamp')
IMAGE='orbit-swamp-worker:20261028.205235.0-sha.1602a617'
def reply(s):
 for i,c in enumerate(s):
  if c in '{[':
   try:
    v,end=json.JSONDecoder().raw_decode(s[i:])
    if not s[i+end:].strip():return v
   except ValueError:pass
 raise RuntimeError('No JSON reply')
class ContainerWorkerTest(unittest.TestCase):
 def test_workflow_labels_dispatch_to_container_and_write_resource(self):
  with tempfile.TemporaryDirectory(prefix='swamp-container-placement-') as tmp:
   root=pathlib.Path(tmp);ext=root/'extensions/behaviors';ext.mkdir(parents=True)
   shutil.copy(REPO/'.swamp.yaml',root/'.swamp.yaml')
   (ext/'probe.ts').write_text('''import { z } from "npm:zod@4.4.3";
  export const behavior={type:"@fixture/worker-placement",version:"2026.10.28.1",globalArguments:z.object({}),resources:{proof:{schema:z.object({host:z.string()}),lifetime:"1h",garbageCollection:1}},methods:{probe:{description:"Prove execution in the worker container",arguments:z.object({}),execute:async (_args:unknown,ctx:any)=>{await Deno.writeTextFile("/var/lib/swamp-worker/placement-proof",Deno.hostname());return {dataHandles:[await ctx.writeResource("proof","proof",{host:Deno.hostname()})]}}}}};''')
   env=dict(os.environ,SWAMP_NO_UPDATE_CHECK='1',SWAMP_NO_TELEMETRY='1')
   for k in ('SWAMP_SERVER_URL','SWAMP_SERVE_URL','SWAMP_SERVER_TOKEN','SWAMP_SERVER_TOKEN_FILE','SWAMP_REPO_DIR','SWAMP_CA_CERT'):env.pop(k,None)
   def cli(*args):
    r=subprocess.run([CLI,*args,'--json'],cwd=root,env=env,capture_output=True,text=True,timeout=40)
    if r.returncode:raise RuntimeError('CLI failed: '+r.stderr[-1000:])
    return reply(r.stdout)
   cli('behavior','create','@fixture/worker-placement','probe')
   wf=cli('workflow','create','worker-placement-probe');pathlib.Path(wf['path']).write_text(json.dumps({'id':wf['id'],'name':'worker-placement-probe','labels':{'pool':'fixture-container'},'queueTimeout':30,'jobs':[{'name':'probe','steps':[{'name':'probe','task':{'type':'behavior','behavior':'probe','methodName':'probe'}}]}]}))
   cli('workflow','validate','worker-placement-probe')
   cli('access','token','mint','fixture-admin','--principal','user:fixture')
   admin=root/'admin';admin.write_text(cli('access','token','reveal','fixture-admin','--yes')['token']);admin.chmod(0o600)
   with socket.socket() as sock:sock.bind(('127.0.0.1',0));port=sock.getsockname()[1]
   url=f'ws://127.0.0.1:{port}';name='swamp-placement-'+uuid.uuid4().hex[:10]
   serve=None
   try:
    with (root/'serve.log').open('w') as log:
     serve=subprocess.Popen([CLI,'serve','--port',str(port),'--host','127.0.0.1','--auth-mode','token','--admins','user:fixture'],cwd=root,env=env,stdout=log,stderr=log)
     for _ in range(100):
      try:
       urllib.request.urlopen(f'http://127.0.0.1:{port}/ready',timeout=1).close();break
      except OSError:time.sleep(.1)
     flags=('--server',url,'--token-file',str(admin))
     enrollment=root/'enrollment';enrollment.write_text(cli('worker','token','create','fixture-container','--duration','10m',*flags)['token']);enrollment.chmod(0o600)
     minted=cli('access','token','mint','fixture-worker-access','--principal','worker:fixture-container',*flags)
     auth=root/'worker-access';auth.write_text(cli('access','token','reveal','fixture-worker-access','--yes')['token']);auth.chmod(0o600)
     cache=root/'cache';cache.mkdir()
     subprocess.run(['docker','run','--detach','--name',name,'--network=host','--volume',f'{root}:/fixture','--volume',f'{cache}:/var/lib/swamp-worker',IMAGE,'worker','connect',url,'--token-file','/fixture/enrollment','--server-token-file','/fixture/worker-access','--cache-dir','/var/lib/swamp-worker','--concurrency','auto','--label','pool=fixture-container'],check=True,capture_output=True,text=True)
     result=cli('workflow','run','worker-placement-probe',*flags)
     proof=cache/'placement-proof';assert proof.exists(),json.dumps(result)
     host=proof.read_text();assert not pathlib.Path("/var/lib/swamp-worker").exists(), "fixture must not expose worker path to serve"
     assert result['status']=='succeeded'
     assert (cache/'placement-proof').exists()
   finally:
    subprocess.run(['docker','rm','-f',name],capture_output=True,timeout=20)
    if serve is not None:
     serve.terminate()
     try:serve.wait(timeout=15)
     except subprocess.TimeoutExpired:serve.kill();serve.wait(timeout=5)

if __name__ == '__main__':
 unittest.main()
