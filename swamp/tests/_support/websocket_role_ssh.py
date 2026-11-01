"""Disposable SSH process seam; production never imports this fixture."""
import io
import json
import os
import shlex
import subprocess
import sys
from pathlib import Path

FIXTURE_ROOT = None
root=Path(FIXTURE_ROOT)
if '-M' in sys.argv or '-O' in sys.argv:raise SystemExit(0)
body=sys.stdin.read()
command=shlex.split(sys.argv[-1])
state_path=root/'state.json'
state=json.loads(state_path.read_text())
failure=(root/'failure').read_text() if (root/'failure').exists() else None


def log(action):
    with (root/'audit.jsonl').open('a') as out:out.write(json.dumps({'action':action})+'\n')
    if action==failure:raise SystemExit(1)


def control(argv):
    verb=argv[argv.index('/usr/bin/systemctl')+1]
    log(verb)
    if verb in ['is-active','is-enabled']:return 0 if state['active' if verb=='is-active' else 'enabled'] else 3
    if verb in ['stop','restart','start']:state['active']=verb!='stop'
    if verb in ['enable','disable']:state['enabled']=verb=='enable'
    state_path.write_text(json.dumps(state));return 0


source=body
if '/usr/bin/python3' in command and '-c' in command:
    source=command[command.index('-c')+1]
if source:
    if '\ndef cleanup(data):' in source:log('cleanup')
    source=source.replace('/etc/systemd/system/orbit-websocket.service',str(root/'orbit-websocket.service')).replace('/var/lib/orbit/role-file-snapshots',str(root/'snapshots'))
    original_run=subprocess.run
    def run(argv,**kwargs):
        if '/usr/bin/systemctl' in argv:
            return subprocess.CompletedProcess(argv,control(argv))
        return original_run(argv,**kwargs)
    subprocess.run=run
    sys.stdin=io.StringIO(body if source!=body else '')
    real_fchown=os.fchown
    os.fchown=lambda fd,uid,gid:real_fchown(fd,os.getuid() if uid==0 else uid,os.getgid() if gid==0 else gid)
    exec(compile(source,'<disposable-remote-script>','exec'),{'__name__':'__main__'})  # noqa: S102 - shipped action in a disposable fixture process.
    raise SystemExit(0)
if '/usr/bin/systemctl' in command:raise SystemExit(control(command))
if '/usr/bin/systemd-analyze' in command:log('unit_verify');raise SystemExit(0)
if '/usr/bin/git' in command:
    log('git')
    if 'checkout' in command:
        release=Path(command[command.index('-C')+1]);(release/'artisan').write_text('fixture');(release/'vendor').mkdir(exist_ok=True)
    raise SystemExit(0)
if '/usr/bin/composer' in command:log('composer');raise SystemExit(0)
if '/usr/bin/php' in command:log('artisan_verify');raise SystemExit(0)
if '/usr/bin/test' in command:
    index=command.index('/usr/bin/test');flag,path=command[index+1:index+3];raise SystemExit(0 if (Path(path).is_file() if flag=='-f' else Path(path).is_dir()) else 1)
raise SystemExit('Unknown disposable remote action')
