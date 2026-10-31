"""Real disposable SQL user action; native scheduling is tested separately."""
import json
import subprocess
import sys
import uuid
from pathlib import Path

ROOT=Path(__file__).resolve().parents[4]
SCRIPTS=ROOT/'extensions/behaviors/operations/database-server-commands/scripts'
sys.path.insert(0,str(SCRIPTS))
from server_common import credentials
from wait_ready import wait


def effect(driver):
    postgres=driver=='pgsql';suffix=uuid.uuid4().hex[:12]
    container='orbit-process-999996-user-create-'+suffix
    password='private-user-'+uuid.uuid4().hex
    intent={'driver':driver,'container':container,'username':'reader','replication':postgres,'known_user':False}
    def docker(args,check=True):
        result=subprocess.run(['/usr/bin/docker',*args],text=True,capture_output=True,timeout=120,check=False)
        if check and result.returncode:raise AssertionError('Disposable Docker fixture command failed')
        return result.stdout
    def action(file,sql):
        argv=[sys.executable,'-B',str(SCRIPTS/file),json.dumps(intent)]
        assert password not in repr(argv)
        value=subprocess.run(argv,input=json.dumps({'password':'','sql':sql}),text=True,capture_output=True,timeout=180,check=False)
        assert password not in value.stdout+value.stderr
        assert value.returncode==0, 'Disposable SQL user action failed: '+value.stdout
        return json.loads(value.stdout)
    try:
        docker(['run','-d','--rm','--network','none','--name',container,
                '--env','POSTGRES_HOST_AUTH_METHOD=trust' if postgres else 'MYSQL_ALLOW_EMPTY_PASSWORD=yes',
                '--health-cmd','pg_isready --host=127.0.0.1 --username=postgres' if postgres else 'mysqladmin --protocol=TCP --host=127.0.0.1 ping --silent',
                '--health-interval','1s','--health-timeout','3s','--health-retries','120',
                'postgres:18-alpine' if postgres else 'mysql:8.0',
                *(['postgres','-c','wal_level=logical'] if postgres else [])])
        assert wait(intent,{})=={'ready':True}
        sql=(f"CREATE ROLE reader LOGIN REPLICATION PASSWORD '{password}'; GRANT pg_read_all_data TO reader;" if postgres
             else f"CREATE USER 'reader'@'%' IDENTIFIED BY '{password}'; GRANT SELECT ON *.* TO 'reader'@'%';")
        if postgres:assert action('user_wal.py',sql)=={'ready':True}
        assert action('user_apply.py',sql)=={'backend':'active'}
        # A real login uses a protected client environment file, never private argv.
        with credentials(intent,{'password':password}) as envfile:
            client=['psql','--host=127.0.0.1','--username=reader','--dbname=postgres','--no-align','--tuples-only','--quiet'] if postgres else ['mysql','--host=127.0.0.1','--protocol=TCP','--user=reader','--batch','--skip-column-names']
            login=subprocess.run(['/usr/bin/docker','exec','-i','--env-file',envfile,container,*client],input='SELECT 1;\n',text=True,capture_output=True,timeout=15,check=False)
            assert login.returncode==0 and login.stdout.strip()=='1'
        intent['known_user']=True
        update=f"ALTER ROLE reader PASSWORD '{password}';" if postgres else f"ALTER USER 'reader'@'%' IDENTIFIED BY '{password}';"
        assert action('user_apply.py',update)=={'backend':'active'}
        print(json.dumps({'driver':driver,'user_created':True,'login':True,'recorded_repair':True}))
    finally:
        docker(['rm','--force',container],check=False)


if __name__=='__main__':effect(sys.argv[1])
