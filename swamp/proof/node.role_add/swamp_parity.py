"""Read the Swamp assignment and public host facts after the real CLI request."""

import json
import os
import shlex
import subprocess
from pathlib import Path


def require(value, message):
    if not value:
        raise ValueError(message)


def command(prefix, node, argv):
    result = subprocess.run(
        [*prefix, node, "--timeout=90", "--argv=" + json.dumps(argv)],
        check=True,
        capture_output=True,
        text=True,
        timeout=100,
    )
    return json.loads(result.stdout)


def receipt(operation):
    require(operation["kind"] == "node.role_add", "unexpected-kind")
    require(operation["status"] == "succeeded", "operation-not-succeeded")
    intent = operation["input"]["intent"]["target"]
    require(intent["role"] == "swamp" and intent["created"] is True, "wrong-assignment")
    require(operation["result"]["target_completed"] is True, "target-incomplete")
    return intent


# This reads public binary/unit facts and private-file metadata, never values.
HOST = r"""
import hashlib,json,pathlib,pwd,stat,subprocess,sys
expected=json.loads(sys.argv[1])
def require(value,message):
 if not value:raise ValueError(message)
def command(argv):return subprocess.run(argv,check=True,capture_output=True,text=True,timeout=20).stdout
binary=pathlib.Path('/usr/local/bin/swamp')
with binary.open('rb') as stream:require(hashlib.file_digest(stream,'sha256').hexdigest()==expected['sha256'],'wrong-official-pin')
account=pwd.getpwnam('orbit')
for name in ['cert.pem','key.pem']:
 metadata=pathlib.Path('/home/orbit/.config/swamp/serve-tls/'+name).lstat()
 require(stat.S_ISREG(metadata.st_mode) and stat.S_IMODE(metadata.st_mode)==0o600 and metadata.st_uid==account.pw_uid and metadata.st_gid==account.pw_gid,'tls-pair-custody')
for unit in ['orbit-swamp-serve.service','orbit-swamp-tls.service','orbit-node-worker.service','orbit-swamp209-migration-a.service','orbit-swamp209-migration-b.service','orbit-swamp209-services.service']:
 command(['/usr/bin/systemctl','is-active','--quiet',unit])
checkout=pathlib.Path('/home/orbit/apps/orbit-monorepo-staging/checkout')
for name,source in [('.swamp','/home/orbit/apps/orbit-swamp/default/.swamp'),('vaults','/home/orbit/apps/orbit-swamp/default/vaults')]:
 require((checkout/'swamp'/name).samefile(source),'native-state-not-separated')
entry=pathlib.Path('/usr/local/libexec/orbit-swamp-release-load.py')
require(entry.is_symlink() and entry.resolve()==pathlib.Path('/usr/local/libexec/orbit-swamp-release-load')/expected['source_sha']/'release_load.py','canonical-load-entry-missing')
print(json.dumps({'host_proven':True}))
"""


def main():
    prefix = shlex.split(os.environ["PROOF_EXEC"])
    operation_id = os.environ["PROOF_OPERATION_ID"]
    require(operation_id.isdecimal(), "bad-operation-id")
    operation = command(
        prefix, "operator", ["orbit", "operation:show", operation_id, "--json"]
    )
    intent = receipt(operation)
    assignments = command(
        prefix,
        "operator",
        ["orbit", "node:role:list", str(intent["node_id"]), "--json"],
    )["assignments"]
    matching = [
        row
        for row in assignments
        if row["id"] == intent["assignment_id"] and row["role"] == "swamp"
    ]
    require(
        len(matching) == 1 and matching[0]["status"] == "active",
        "assignment-not-active",
    )
    # The pin comes from the canonical public provider, not the observed target.
    import re

    source = Path("swamp/extensions/behaviors/node-worker/config.ts").read_text()
    pin = re.search(r'sha256:\s*"([a-f0-9]{64})"', source)
    require(pin is not None, "canonical-pin-missing")
    observed = command(
        prefix,
        "spare",
        [
            "sudo",
            "-n",
            "python3",
            "-I",
            "-c",
            HOST,
            json.dumps({"sha256": pin[1], "source_sha": intent["source_sha"]}),
        ],
    )
    require(observed["host_proven"] is True, "host-not-proven")
    print(
        json.dumps(
            {
                "parity": "passed",
                "role": "swamp",
                "assignment_id": intent["assignment_id"],
            }
        )
    )


if __name__ == "__main__":
    main()
