"""Disposable real engine effects through the official SSH native DAG test seam."""

import hashlib
import json
import shutil
import socket
import subprocess
import sys
import uuid
from pathlib import Path

from server_create_native_test import ROOT, ServerCreateNative


def docker(*args, check=True):
    result = subprocess.run(['/usr/bin/sudo','-n','--','/usr/bin/docker',*args], text=True, capture_output=True, timeout=120, check=False)
    if check and result.returncode:
        raise RuntimeError('Disposable Docker fixture command failed')
    return result.stdout


def effect(driver):
    fixture=ServerCreateNative('test_initialization_readiness_scrub_and_terminal_result')
    fixture.setUp()
    suffix=uuid.uuid4().hex[:12]
    container='orbit-process-999998-server-create-'+suffix
    volume='orbit-server-create-'+suffix+'-data'
    with socket.socket() as listener:
        listener.bind(('127.0.0.1',0));port=listener.getsockname()[1]
    pg=driver=='pgsql';redis=driver=='redis'
    operation_id=int(uuid.uuid4().hex[:7],16)
    remote=Path('/tmp/orbit-database-server-'+str(operation_id))
    password=uuid.uuid4().hex+suffix
    try:
        intent=fixture.public['intent']
        intent.update(driver=driver,process_id=999998,container=container,initialize=not redis,validate_existing=False,was_active=False,
            host='127.0.0.1',port=port,volume=volume if redis else None)
        intent['container_input']={'image':'valkey/valkey:8.1' if redis else 'postgres:18-alpine' if pg else 'mysql:8.0',
            'command':['valkey-server','--appendonly','yes','--dir','/data','--aclfile','/data/users.acl'] if redis else ['postgres' if pg else 'mysqld'],
            'restart':'unless-stopped','working_directory':None,'ports':[f'127.0.0.1:{port}:{6379 if redis else 5432 if pg else 3306}'],
            'mounts':[{'source':volume,'target':'/data' if redis else '/var/lib/postgresql' if pg else '/var/lib/mysql','read_only':False}]}
        fixture.public['operation_id']=operation_id
        fixture.material={'password':password,'environment':{} if redis else {'POSTGRES_PASSWORD' if pg else 'MYSQL_ROOT_PASSWORD':password},
            'acl':f'user default on #{hashlib.sha256(password.encode()).hexdigest()} ~* &* +@all\n' if redis else '',
            'payload':{'users':[],'default_open':False}}
        fixture.secrets=(password,)
        fixture.cli('vault','put','orbit-control-inputs','database-server-create-intent-17','--force',input=json.dumps(fixture.material))
        fake=fixture.repo/'fixture-ssh'
        fake.write_text('#!'+sys.executable+'\n'+'''import shlex,subprocess,sys
if '-M' in sys.argv or '-O' in sys.argv: sys.exit(0)
r=subprocess.run(shlex.split(sys.argv[-1]),input=sys.stdin.buffer.read(),capture_output=True,check=False)
sys.stdout.buffer.write(r.stdout);sys.stderr.buffer.write(r.stderr);sys.exit(r.returncode)
''')
        fake.chmod(0o700)
        scp=fixture.repo/'fixture-scp'
        scp.write_text('#!'+sys.executable+'\n'+'''import shutil,sys
shutil.copytree(sys.argv[-2],sys.argv[-1].split(':',1)[1],dirs_exist_ok=True)
''')
        scp.chmod(0o700)
        fixture.native_timeout=180
        run=fixture.run_server()
        fixture.assertEqual(run['status'],'succeeded')
        config=json.loads(docker('inspect','--format','{{json .Config.Env}}',container))
        fixture.assertFalse(any(password in s or s.startswith(('MYSQL_ROOT_PASSWORD=','POSTGRES_PASSWORD=')) for s in config))
        current_spec=intent['spec'] if redis else intent['scrub_spec']
        fixture.assertEqual(docker('inspect','--format','{{index .Config.Labels "orbit.process.spec"}}',container).strip(),current_spec)
        # A second explicit repair keeps the ready container and never adds initialization material.
        intent.update(initialize=False,was_active=True,spec=current_spec)
        fixture.material['environment']={}
        fixture.cli('vault','put','orbit-control-inputs','database-server-create-intent-17','--force',input=json.dumps(fixture.material))
        fixture.run_server()
        fixture.assertNotIn(password,docker('inspect','--format','{{json .Config.Env}}',container))
        print(json.dumps({'driver':driver,'native_run':run['id'],'scrubbed':True,'repaired':True}))
    finally:
        for name in (container,container+'-candidate',container+'-rollback-running',container+'-rollback-stopped',container+'-failed'):
            docker('rm','--force',name,check=False)
        docker('volume','rm',volume,check=False)
        shutil.rmtree(remote,ignore_errors=True)
        fixture.doCleanups()


if __name__=='__main__':effect(sys.argv[1])
