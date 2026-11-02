"""Gateway registration-to-native-cleanup proof using the shared disposable T3 contract."""
import importlib.util
import json
import os
import secrets
import signal
import subprocess
import sys
import time
from pathlib import Path

SOURCE = Path(__file__).resolve().parents[3]
STATE = Path('/home/orbit/.orbit/swamp-proof/conn-gateway-sessions')
DATA = STATE / 'server.json'
spec = importlib.util.spec_from_file_location('revocation', Path(__file__).with_name('revocation_fixture.py'))
t3 = importlib.util.module_from_spec(spec)
spec.loader.exec_module(t3)
t3.STATE, t3.DATA = STATE, DATA


def php(code, body=None):
    bootstrap = r"require 'vendor/autoload.php'; $app=require 'bootstrap/app.php'; $app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap(); "
    result = subprocess.run(['runuser', '-u', 'orbit', '--', 'php', '-r', bootstrap + code], input=json.dumps(body) if body is not None else None,
                            cwd=SOURCE/'apps/gateway', text=True, capture_output=True, timeout=30, check=True)
    return result.stdout.strip()


def setup(case):
    STATE.mkdir(mode=0o700, exist_ok=True)
    up = json.loads((STATE.parent/'up.json').read_text())
    if case == 'retry':
        data = json.loads(DATA.read_text())
        assert data['case'] == 'gateway-partial'
        assert data['revoked_sessions'] == ['first-session']
        data['case'] = 'gateway-retry'
        data['reads'] = 0
        data['revoked_sessions'] = []
        DATA.write_text(json.dumps(data))
        print('{}')
        return
    if (STATE/'pid').exists():
        try:os.kill(int((STATE/'pid').read_text()), signal.SIGTERM)
        except ProcessLookupError:pass
    private, admin = secrets.token_urlsafe(32), secrets.token_urlsafe(32)
    data = {'case':'gateway-'+case, 'admin':admin, 'private':private, 'reads':0, 'revoked_sessions':[], 'revoked_links':[], 'sessions':[
        {'sessionId':'first-session', 'client':{'label':'Orbit Gateway'}, 'private':private},
        {'sessionId':'second-session', 'client':{'label':'Orbit Gateway'}, 'private':private},
        {'sessionId':'gateway', 'current':True, 'client':{'label':'Orbit Gateway'}, 'private':private},
        {'sessionId':'foreign', 'client':{'label':'phone'}, 'private':private},
    ]}
    DATA.write_text(json.dumps(data));DATA.chmod(0o600)
    (STATE/'port').unlink(missing_ok=True)
    with (STATE/'server.log').open('w') as log:
        process = subprocess.Popen(['python3','-I',str(Path(__file__).resolve()),'serve'],stdin=subprocess.DEVNULL,stdout=log,stderr=log,start_new_session=True)
    (STATE/'pid').write_text(str(process.pid))
    for _ in range(100):
        if (STATE/'port').exists():break
        time.sleep(.05)
    port = int((STATE/'port').read_text())
    php(r'''$v=json_decode(stream_get_contents(STDIN),true,flags:JSON_THROW_ON_ERROR);
Orbit\Extensions\Conn\Models\ConnPairing::query()->delete();
Orbit\Extensions\Conn\Models\ConnEnvironment::query()->delete();
$env=Orbit\Extensions\Conn\Models\ConnEnvironment::query()->create(['environment_id'=>'revoke-proof','label'=>'proof','node_id'=>209,'url'=>$v['url'],'admin_session'=>'prior-admin-session','admin_session_expires_at'=>now()->addDay(),'registered_at'=>now()]);
Orbit\Extensions\Conn\Models\ConnPairing::query()->create(['node_id'=>$v['owner'],'conn_environment_id'=>$env->id,'client_label'=>'phone','pairing_link_id'=>'foreign-link','credential'=>$v['private']]);''', {'url':f'http://127.0.0.1:{port}', 'owner':up['nodes']['operator'], 'private':private})
    print(json.dumps({'secrets':{'url':f'http://127.0.0.1:{port}', 'admin':admin}}))


def parity():
    data=json.loads(DATA.read_text());operation=json.loads(Path(os.environ['PROOF_OPERATION_FILE']).read_text())
    success=data['case'] in ['gateway-success','gateway-retry']
    assert operation['kind']=='conn.gateway_sessions_revoke'
    assert operation['status']==('succeeded' if success else 'failed'),operation
    assert operation['input']['cleanup_version']==operation['input']['intent']['revision']==1
    assert operation['input']['intent']['labels']==['Orbit Gateway'] and operation['input']['intent']['links']==[]
    assert data['reads']==1 and data['revoked_links']==[]
    expected=['first-session','second-session'] if data['case']=='gateway-success' else ['first-session'] if data['case']=='gateway-partial' else ['second-session'] if data['case']=='gateway-retry' else []
    assert sorted(data['revoked_sessions'])==expected,data['revoked_sessions']
    assert {'gateway','foreign'}.issubset({row['sessionId'] for row in data['sessions']})
    state=json.loads(php(r'''$env=Orbit\Extensions\Conn\Models\ConnEnvironment::query()->sole(); echo json_encode(['version'=>$env->cleanup_version,'completed'=>$env->cleanup_completed_version,'admin'=>$env->admin_session,'pairing'=>Orbit\Extensions\Conn\Models\ConnPairing::query()->sole()->only(['credential','revoked_at']),'operations'=>App\Models\Operation::query()->where('kind','conn.gateway_sessions_revoke')->where('target_id',$env->id)->count()]);'''))
    assert state['version']==1 and state['completed']==(1 if success else 0)
    assert state['admin']==data['admin']
    assert state['pairing']=={'credential':data['private'],'revoked_at':None}
    assert state['operations']==(2 if data['case']=='gateway-retry' else 1)
    if success:
        assert operation['result']=={'backend':'revoked','revoked_sessions':len(expected)}
        # The already-complete revision is a record-only 204 and admits no new run.
        up=json.loads((STATE.parent/'up.json').read_text())
        request={'caller':up['operator_address'],'method':'POST','path':'conn/environments/revoke-proof/sessions/cleanup','body':{},'operation':False,'key':'proof-completed-cleanup','seconds':30}
        reply=subprocess.run(['runuser','-u','orbit','--','php',str(SOURCE/'tools/swamp-proof-guest/gateway.php'),'request'],input=json.dumps(request),cwd=SOURCE/'apps/gateway',capture_output=True,text=True,timeout=40,check=True)
        assert json.loads(reply.stdout)['http_status']==204
    else:assert operation['error_code']=='conn.revocation_failed'
    public=json.dumps(operation)+Path(os.environ['PROOF_OUTPUTS']).read_text()
    assert data['admin'] not in public and data['private'] not in public
    assert not list(Path('/tmp').glob('orbit-conn-revoke.*'))
    print('Registration admits one native run; one inventory; exact old Gateway sessions; current admin and pairing preserved; revision projection, 204/no-run, retry and private cleanup checked.')


if __name__=='__main__':
    if sys.argv[1]=='serve':t3.serve()
    elif sys.argv[1]=='setup':setup(sys.argv[2])
    else:parity()
