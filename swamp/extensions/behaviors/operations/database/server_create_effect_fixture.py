"""Real disposable server actions; native DAG control is tested separately.

Run checked-in action files without a developer extension cache or CLI account.
The fixed sequences below exercise creation, scrubbing and idempotent repair;
they do not interpret the workflow or implement a second scheduler.
"""

import hashlib
import json
import socket
import subprocess
import sys
import uuid
from pathlib import Path

ROOT = Path(__file__).resolve().parents[4]
SCRIPTS = ROOT / 'extensions/behaviors/operations/database-server-commands/scripts'
PROCESS = ROOT / 'extensions/behaviors/operations/process-commands/scripts'


def docker(*args, check=True):
    result = subprocess.run(['/usr/bin/sudo','-n','--','/usr/bin/docker',*args], text=True, capture_output=True, timeout=120, check=False)
    if check and result.returncode:
        raise RuntimeError('Disposable Docker fixture command failed')
    return result.stdout


def effect(driver):
    suffix=uuid.uuid4().hex[:12]
    container='orbit-process-999998-server-create-'+suffix
    volume='orbit-server-create-'+suffix+'-data'
    with socket.socket() as listener:
        listener.bind(('127.0.0.1',0));port=listener.getsockname()[1]
    pg=driver=='pgsql';redis=driver=='redis'
    password=uuid.uuid4().hex+suffix
    intent={'driver':driver,'process_id':999998,'container':container,'initialize':not redis,'validate_existing':False,'was_active':False,
        'default_open':False,'host':'127.0.0.1','port':port,'volume':volume if redis else None,'spec':'b'*64,'scrub_spec':'c'*64,
        'container_input':{'image':'valkey/valkey:8.1' if redis else 'postgres:18-alpine' if pg else 'mysql:8.0',
            'command':['valkey-server','--appendonly','yes','--dir','/data','--aclfile','/data/users.acl'] if redis else ['postgres' if pg else 'mysqld'],
            'restart':'unless-stopped','working_directory':None,'ports':[f'127.0.0.1:{port}:{6379 if redis else 5432 if pg else 3306}'],
            'mounts':[{'source':volume,'target':'/data' if redis else '/var/lib/postgresql' if pg else '/var/lib/mysql','read_only':False}]}}
    material={'password':password,'environment':{} if redis else {'POSTGRES_PASSWORD' if pg else 'MYSQL_ROOT_PASSWORD':password},
        'acl':f'user default on #{hashlib.sha256(password.encode()).hexdigest()} ~* &* +@all\n' if redis else '',
        'payload':{'users':[],'default_open':False}}

    def run(argv, check=True):
        result=subprocess.run(['/usr/bin/sudo','-n','--','/usr/bin/python3','-B',*argv],input=json.dumps(material),text=True,capture_output=True,timeout=180,check=False)
        if password in result.stdout or password in result.stderr or password in repr(argv):
            raise AssertionError('Private material left the client action')
        if check and result.returncode:
            raise AssertionError('Disposable action failed: '+result.stdout)
        return json.loads(result.stdout)

    def database(script, check=True):
        return run([str(SCRIPTS/script),json.dumps(intent)],check)

    def process(script, *, spec='b'*64, slot='canonical', operation='start', destination='canonical', desired='running', private=False, scrub=False):
        return run([str(SCRIPTS/'process.py'),json.dumps(intent),slot,spec,script,str(PROCESS),operation,destination,desired,
                    'true' if private else 'false','true' if scrub else 'false'])

    def assert_ready(spec):
        assert process('verify.py',spec=spec)=={'ready':True,'state':'running'}
        assert database('wait_ready.py')=={'ready':True}
        assert database('ready.py')=={'ready':True}

    try:
        assert database('inspect_policy.py')=={'rebuild':redis}
        if redis:
            assert database('write_acl.py')=={'ready':True}
        assert process('pull_image.py',private=True)=={'ready':True}
        assert process('create_container.py',private=True)=={'ready':True}
        assert process('control.py')=={'ready':True}
        assert_ready(intent['spec'])
        if not redis:
            assert process('pull_image.py',spec=intent['scrub_spec'],private=True,scrub=True)=={'ready':True}
            assert process('create_container.py',spec=intent['scrub_spec'],slot='candidate',private=True,scrub=True)=={'ready':True}
            assert process('rename_container.py',destination='rollback-running')=={'ready':True}
            assert process('control.py',slot='rollback-running',operation='stop')=={'ready':True}
            assert process('rename_container.py',spec=intent['scrub_spec'],slot='candidate')=={'ready':True}
            assert process('control.py',spec=intent['scrub_spec'])=={'ready':True}
            assert_ready(intent['scrub_spec'])
            assert process('remove_container.py',slot='rollback-running')=={'ready':True}
        current_spec=intent['spec'] if redis else intent['scrub_spec']
        config=json.loads(docker('inspect','--format','{{json .Config.Env}}',container))
        assert not any(password in value or value.startswith(('MYSQL_ROOT_PASSWORD=','POSTGRES_PASSWORD=')) for value in config)
        assert docker('inspect','--format','{{index .Config.Labels "orbit.process.spec"}}',container).strip()==current_spec
        # A second explicit repair keeps the same ready container and no initializer.
        identity=docker('inspect','--format','{{.Id}}',container).strip()
        intent.update(initialize=False,was_active=True,spec=current_spec)
        material['environment']={}
        assert database('inspect_policy.py')=={'rebuild':False}
        assert process('create_container.py',spec=current_spec,private=True,scrub=True)=={'ready':True}
        assert process('control.py',spec=current_spec)=={'ready':True}
        assert_ready(current_spec)
        assert docker('inspect','--format','{{.Id}}',container).strip()==identity
        assert password not in docker('inspect','--format','{{json .Config.Env}}',container)
        if redis:
            # A real refused adoption performs only reads and keeps the operator's container.
            intent.update(validate_existing=True,was_active=False)
            material['password']='wrong-adoption-password'
            assert database('inspect_policy.py',check=False)=={'error_code':'database.server_adoption_refused'}
            assert docker('inspect','--format','{{.Id}}',container).strip()==identity
        print(json.dumps({'driver':driver,'scrubbed':True,'repaired':True}))
    finally:
        for name in (container,container+'-candidate',container+'-rollback-running',container+'-rollback-stopped',container+'-failed'):
            docker('rm','--force','--volumes',name,check=False)
        docker('volume','rm',volume,check=False)


if __name__=='__main__':effect(sys.argv[1])
