"""Announce the existing publisher's fixed load procedure in its own thread."""
import json,subprocess
THREAD = 'mcp:launch:client%3Ad6b81294-34c6-41cf-9a96-2326bdf2c033:mx-project-extensions-20261027'
def announce_load(message):
    r=subprocess.run(['/home/nckrtl/.local/bin/t3','thread','send','--json',THREAD,'-'],input=message,text=True,capture_output=True,timeout=30)
    assert r.returncode == 0, 'load_announcement_failed'
    return json.loads(r.stdout)
