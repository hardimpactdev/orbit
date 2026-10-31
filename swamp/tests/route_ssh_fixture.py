"""Run the unmodified official SSH model against disposable local host files."""

import base64
import json
import os
import pwd
import shutil
import sys
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
PIN = (
    "ssh-ed25519 "
    + base64.b64encode(
        b"\x00\x00\x00\x0bssh-ed25519\x00\x00\x00\x20" + b"\x01" * 32
    ).decode()
)


def install_ssh(test):
    source = ROOT / ".swamp/pulled-extensions/@swamp/ssh"
    destination = test.repo / ".swamp/pulled-extensions/@swamp/ssh"
    destination.parent.mkdir(parents=True, exist_ok=True)
    shutil.copytree(source, destination)
    inventory = json.loads(
        (ROOT / "extensions/models/upstream_extensions.json").read_text()
    )
    path = test.repo / "extensions/models/upstream_extensions.json"
    path.parent.mkdir(parents=True, exist_ok=True)
    path.write_text(json.dumps({"@swamp/ssh": inventory["@swamp/ssh"]}))
    tools = test.repo / "ssh-tools"
    tools.mkdir()
    ssh = tools / "ssh"
    ssh.write_text(
        "#!"
        + sys.executable
        + "\n"
        + """import os,re,shlex,subprocess,sys
from pathlib import Path
args=sys.argv[1:]
if '-M' in args or '-O' in args:raise SystemExit(0)
argv=shlex.split(args[-1])
if argv[:3]==['sudo','-n','--']:argv=argv[3:]
if argv[:2]==['sh','-c'] and shlex.split(argv[-1])[:1]==['/usr/bin/install']:argv=shlex.split(argv[-1])
if '/bin/sh' in argv or 'sh' in argv:
    argv[-1]=re.sub(r'sudo --preserve-env=[A-Z_,]+ ', '', argv[-1]).replace('sudo -u caddy ','')
    argv[-1]=re.sub(r'sudo (?!/usr/bin/test)', '', argv[-1])
if any(arg.startswith('/etc/') for arg in argv):raise SystemExit('Fixture refused a real /etc path')
if '/bin/sh' in argv:
    argv[argv.index('-c')+1]=re.sub(r' -o root -g (?:root|caddy)', '', argv[argv.index('-c')+1])
result=subprocess.run(argv,input=sys.stdin.buffer.read(),capture_output=True)
sys.stdout.buffer.write(result.stdout);sys.stderr.buffer.write(result.stderr)
raise SystemExit(result.returncode)
"""
    )
    ssh.chmod(0o755)
    scp = tools / "scp"
    scp.write_text(
        "#!"
        + sys.executable
        + "\n"
        + """import shutil,sys
from pathlib import Path
src=Path(sys.argv[-2]);dst=Path(sys.argv[-1].split(':',1)[1])
if src.is_dir():shutil.copytree(src,dst/src.name,dirs_exist_ok=True)
else:shutil.copyfile(src,dst)
"""
    )
    scp.chmod(0o755)
    test.native_env["PATH"] = str(tools) + ":" + os.environ["PATH"]
    return {
        "nodeHost": "fixture",
        "nodeUser": pwd.getpwuid(os.getuid()).pw_name,
        "nodePort": 22,
        "nodeIdentityFile": "/fixture/existing-key",
        "become": True,
    }
