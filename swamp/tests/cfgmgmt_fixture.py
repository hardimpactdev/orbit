"""Local tool fixtures for native tests of the unmodified official cfgmgmt package."""

import json
import os
import pwd
import shutil
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]


def install_cfgmgmt(test):
    source = ROOT / ".swamp/pulled-extensions/@swamp/cfgmgmt"
    if not source.is_dir():
        raise RuntimeError(
            "Run swamp extension pull @swamp/cfgmgmt before this native test"
        )
    destination = test.repo / ".swamp/pulled-extensions/@swamp/cfgmgmt"
    destination.parent.mkdir(parents=True, exist_ok=True)
    shutil.copytree(source, destination)
    inventory = json.loads(
        (ROOT / "extensions/models/upstream_extensions.json").read_text()
    )
    path = test.repo / "extensions/models/upstream_extensions.json"
    path.parent.mkdir(parents=True, exist_ok=True)
    path.write_text(json.dumps({"@swamp/cfgmgmt": inventory["@swamp/cfgmgmt"]}))
    commands = test.repo / "cfgmgmt-tools"
    commands.mkdir()
    scripts = {
        "ssh": """#!/usr/bin/env python3
import subprocess,sys
if '-M' in sys.argv or '-O' in sys.argv: sys.exit(0)
sys.exit(subprocess.call(['/bin/sh','-c',sys.argv[-1]]))
""",
        "scp": """#!/usr/bin/env python3
import shutil,sys
shutil.copyfile(sys.argv[-2],sys.argv[-1].split(':',1)[1])
""",
    }
    for name, script in scripts.items():
        path = commands / name
        path.write_text(script)
        path.chmod(0o700)
    test.native_env["PATH"] = str(commands) + ":" + os.environ["PATH"]
    return {"nodeHost": "fixture", "nodeUser": pwd.getpwuid(os.getuid()).pw_name}
