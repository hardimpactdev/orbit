"""Real disposable Valkey read and refusal proof without credentials in argv."""
import json
import os
import socket
import subprocess
import sys
import tempfile
import uuid
from pathlib import Path

SCRIPTS=Path(__file__).resolve().parents[1]/'database-server-commands/scripts'
sys.path.insert(0,str(SCRIPTS))
from server_common import credentials
from wait_ready import wait


def effect():
    container='orbit-process-999995-update-'+uuid.uuid4().hex[:12]
    password='private-update-'+uuid.uuid4().hex
    with socket.socket() as sock:
        sock.bind(('127.0.0.1',0));port=sock.getsockname()[1]
    intent={'validate_index':True,'host':'127.0.0.1','port':port,'index':'3'}
    material={'password':password}
    def docker(*args,check=True):
        value=subprocess.run(['/usr/bin/docker',*args],capture_output=True,text=True,check=False,timeout=120)
        if check:assert value.returncode==0,'Disposable Valkey fixture command failed'
        return value.stdout
    def read():
        with credentials({'driver':'redis'},material) as envfile:
            result=docker('exec','-i','--env-file',envfile,container,'valkey-cli','--json','-p',str(port),'CONFIG','GET','databases')
            return json.loads(result)
    def action(success=True):
        argv=[sys.executable,'-B',str(SCRIPTS/'connection_update.py'),json.dumps(intent)]
        assert password not in repr(argv)
        value=subprocess.run(argv,input=json.dumps(material),capture_output=True,text=True,check=False,timeout=120)
        assert password not in value.stdout+value.stderr
        assert (value.returncode==0)==success,'Connection update effect failed: '+value.stdout
        return json.loads(value.stdout)
    with tempfile.TemporaryDirectory(prefix='orbit-valkey-update-') as directory:
        config=Path(directory)/'valkey.conf';config.write_text(f'bind 127.0.0.1\nport {port}\nrequirepass {password}\nsave ""\nappendonly no\n');config.chmod(0o600)
        try:
            docker('run','-d','--rm','--network','host','--user',f'{os.getuid()}:{os.getgid()}','--name',container,
                   '--mount',f'type=bind,source={config},target=/tmp/valkey.conf,readonly',
                   '--health-cmd',f'valkey-cli -p {port} ping','--health-interval','1s','--health-timeout','3s','--health-retries','30',
                   'valkey/valkey:8.1','valkey-server','/tmp/valkey.conf')
            assert wait({'container':container},{})=={'ready':True}
            before=read()
            assert action()=={'backend':'active'}
            assert action()=={'backend':'active'}
            intent['index']='16';assert action(False)=={'error_code':'database.index_invalid'}
            intent['index']='9'*255;assert action(False)=={'error_code':'database.index_invalid'}
            assert read()==before
            intent['index']='3';material['password']='incorrect-fixture-password'
            assert action(False)=={'error_code':'database.server_command_failed'}
            print(json.dumps({'valid':True,'out_of_range':True,'wrong_password_refused':True,'configuration_unchanged':True}))
        finally:docker('rm','--force',container,check=False)


if __name__=='__main__':effect()
