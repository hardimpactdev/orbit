"""Real disposable SQL user/grant policy; native scheduling is tested separately."""
import base64
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
    password="private-user-'\\-"+uuid.uuid4().hex
    material={'password':'','payload':{'password':password,'salt':base64.b64encode(uuid.uuid4().bytes).decode()}}
    intent={'driver':driver,'container':container,'database':'catalog_proof','owner_username':'catalog_owner',
            'username':'reader','read_only':False,'replication':False,'known_user':False}
    client=['psql','--no-psqlrc','--host=127.0.0.1','--username=postgres','--dbname=postgres','--no-align','--tuples-only','--quiet','--set=ON_ERROR_STOP=1'] if postgres else ['mysql','--user=root','--batch','--skip-column-names']
    def docker(args,check=True):
        result=subprocess.run(['/usr/bin/docker',*args],text=True,capture_output=True,timeout=120,check=False)
        if check and result.returncode:raise AssertionError('Disposable Docker fixture command failed')
        return result.stdout
    def sql(statement):
        value=subprocess.run(['/usr/bin/docker','exec','-i',container,*client],input=statement,text=True,capture_output=True,timeout=15,check=False)
        assert value.returncode==0,'Disposable SQL fixture setup/readback failed'
        return value.stdout.strip()
    def action(file='user_apply.py',success=True):
        argv=[sys.executable,'-B',str(SCRIPTS/file),json.dumps(intent)]
        assert password not in repr(argv)
        value=subprocess.run(argv,input=json.dumps(material),text=True,capture_output=True,timeout=180,check=False)
        assert password not in value.stdout+value.stderr
        assert (value.returncode==0)==success, 'Disposable SQL user action failed: '+value.stdout
        if file=='user_apply.py' and success:
            assert json.loads(value.stdout)=={'ready':True}
            return action('user_grant.py')
        return json.loads(value.stdout)
    def login(statement,success=True):
        with credentials(intent,{'password':material['payload']['password']}) as envfile:
            argv=['psql','--no-psqlrc','--host=127.0.0.1','--username=reader','--dbname=catalog_proof','--no-align','--tuples-only','--quiet','--set=ON_ERROR_STOP=1'] if postgres else ['mysql','--host=127.0.0.1','--protocol=TCP','--user=reader','--database=catalog_proof','--batch','--skip-column-names']
            value=subprocess.run(['/usr/bin/docker','exec','-i','--env-file',envfile,container,*argv],input=statement,text=True,capture_output=True,timeout=15,check=False)
            assert (value.returncode==0)==success, 'Disposable login/privilege expectation failed'
            return value.stdout.strip()
    try:
        docker(['run','-d','--rm','--network','none','--name',container,
                '--env','POSTGRES_HOST_AUTH_METHOD=scram-sha-256' if postgres else 'MYSQL_ALLOW_EMPTY_PASSWORD=yes',
                *(['--env','POSTGRES_PASSWORD=fixture-root'] if postgres else []),
                '--health-cmd','pg_isready --host=127.0.0.1 --username=postgres' if postgres else 'mysqladmin --protocol=TCP --host=127.0.0.1 ping --silent',
                '--health-interval','1s','--health-timeout','3s','--health-retries','120',
                'postgres:18-alpine' if postgres else 'mysql:8.0',
                *(['postgres','-c','wal_level=logical'] if postgres else [])])
        # Fixture setup uses the container's local trust socket; the action authenticates over TCP.
        if postgres:
            material['password']='fixture-root'
            client[:]=['psql','--no-psqlrc','--username=postgres','--no-align','--tuples-only','--quiet','--set=ON_ERROR_STOP=1']
        assert wait(intent,{})=={'ready':True}
        if postgres:
            sql('CREATE ROLE catalog_owner LOGIN NOSUPERUSER NOCREATEDB NOCREATEROLE; CREATE DATABASE catalog_proof OWNER catalog_owner; REVOKE ALL ON DATABASE catalog_proof FROM PUBLIC;')
            sql('\\connect catalog_proof\nSET ROLE catalog_owner; CREATE TABLE current_table (id bigint GENERATED ALWAYS AS IDENTITY, value text); INSERT INTO current_table(value) VALUES (\'proof\'); RESET ROLE;')
        else:
            sql('CREATE DATABASE catalog_proof; CREATE DATABASE catalogXproof; CREATE TABLE catalog_proof.current_table(id bigint, value text); INSERT INTO catalog_proof.current_table VALUES(1,\'proof\');')
        assert action()=={'backend':'active'}
        assert login('SELECT value FROM current_table;')=='proof'
        login("INSERT INTO current_table(value) VALUES ('writer');" if postgres else "INSERT INTO current_table VALUES (2,'writer');")
        if postgres:
            before=sql("SELECT rolpassword FROM pg_authid WHERE rolname='reader';")
            assert action()=={'backend':'active'}
            assert sql("SELECT rolpassword FROM pg_authid WHERE rolname='reader';")==before
            salt=material['payload']['salt'];material['payload']['salt']=base64.b64encode(uuid.uuid4().bytes).decode()
            assert action(success=False)=={'error_code':'database.server_admin_failed'}
            assert sql("SELECT rolpassword FROM pg_authid WHERE rolname='reader';")==before
            material['payload']['salt']=salt
        else:
            # An underscore in an exact database name must not grant a sibling wildcard scope.
            sql('CREATE TABLE catalogXproof.sibling(value int); INSERT INTO catalogXproof.sibling VALUES (7);')
            login('SELECT value FROM catalogXproof.sibling;',success=False)
            assert sql("SELECT COUNT(*) FROM mysql.db WHERE User='reader' AND Db='catalogXproof';")=='0'
        intent.update(known_user=True,read_only=True,replication=postgres)
        material['payload']['password']=password+'-rotated'
        if postgres:assert action('user_wal.py')=={'ready':True}
        assert action()=={'backend':'active'}
        login('SELECT value FROM current_table;')
        login("INSERT INTO current_table(value) VALUES ('refused');" if postgres else "INSERT INTO current_table VALUES (3,'refused');",success=False)
        if postgres:
            sql('\\connect catalog_proof\nSET ROLE catalog_owner; CREATE TABLE future_table (id bigint GENERATED ALWAYS AS IDENTITY); RESET ROLE;')
            assert sql("\\connect catalog_proof\nSELECT has_table_privilege('reader','current_table','SELECT')::text || '|' || has_table_privilege('reader','current_table','INSERT')::text || '|' || has_table_privilege('reader','future_table','SELECT')::text || '|' || has_table_privilege('reader','future_table','INSERT')::text;")=='true|false|true|false'
            assert sql("SELECT rolcanlogin::text || '|' || rolreplication::text || '|' || rolsuper::text || '|' || rolcreatedb::text || '|' || rolcreaterole::text FROM pg_roles WHERE rolname='reader';")=='true|true|false|false|false'
            login("SELECT slot_name FROM pg_create_logical_replication_slot('orbit_sync_test','pgoutput');")
            login("SELECT pg_drop_replication_slot('orbit_sync_test');")
            intent['replication']=False
            assert action()=={'backend':'active'}
            assert sql("SELECT rolreplication::text FROM pg_roles WHERE rolname='reader';")=='false'
        print(json.dumps({'driver':driver,'user_created':True,'login':True,'recorded_repair':True}))
    finally:
        docker(['rm','--force',container],check=False)


if __name__=='__main__':effect(sys.argv[1])
