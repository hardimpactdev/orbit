"""Q-SECRET probe: run only as root in a bin/swamp-proof minimal VM."""

import base64
import hashlib
import json
import os
import secrets
import subprocess
import time
from pathlib import Path

import yaml

SOURCE = Path("/home/orbit/swamp-proof/src")
REPO = SOURCE / "swamp"
STATE = Path("/home/orbit/.orbit/swamp-proof")
ENV = {
    "PATH": "/usr/local/bin:/usr/bin:/bin",
    "HOME": "/home/orbit",
    "SWAMP_HOME": str(STATE / "home"),
    "SWAMP_CONFIG_DIR": str(STATE / "config"),
    "SWAMP_NO_UPDATE_CHECK": "1",
    "SWAMP_NO_TELEMETRY": "1",
    "SWAMP_API_KEY_FILE": "/home/orbit/.config/swamp/club-api-key",
}
markers = [secrets.token_hex(32), secrets.token_hex(32)]
seen = []


def clean(raw, label):
    for marker in markers:
        assert (
            marker not in raw
            and base64.b64encode(("QSECRET=" + marker + "\n").encode()).decode()
            not in raw
        ), "Q-SECRET leak in " + label
    seen.append({"surface": label, "sha256": hashlib.sha256(raw.encode()).hexdigest()})


def call(args, body=None, server=True, ok=True):
    argv = [
        "/usr/sbin/runuser",
        "-u",
        "orbit",
        "--",
        "swamp",
        *args,
        "--repo-dir",
        str(REPO),
        "--json",
    ]
    if server:
        argv += [
            "--server",
            "wss://swamp.orbit:443",
            "--token-file",
            str(STATE / "credentials/operator"),
            "--ca-cert",
            str(STATE / "tls/ca.pem"),
        ]
    r = subprocess.run(
        argv,
        input=body,
        text=True,
        capture_output=True,
        cwd=REPO,
        env=ENV,
        timeout=120,
        check=False,
    )
    clean(r.stdout + "\n" + r.stderr, "CLI " + " ".join(args[:3]))
    if ok:
        assert r.returncode == 0, r.stdout + r.stderr
    decoder = json.JSONDecoder()
    for i, char in enumerate(r.stdout):
        if char in "{[":
            try:
                val, end = decoder.raw_decode(r.stdout[i:])
                if not r.stdout[i + end :].strip():
                    return val
            except ValueError:
                pass
    if ok:
        raise AssertionError("Missing JSON reply")
    return {"returncode": r.returncode, "output": r.stdout + r.stderr}


name = "proof-qsecret-file"
created = call(["workflow", "get", name], ok=False)
if "path" not in created:
    created = call(["workflow", "create", name])
path = Path(created["path"])
if not path.is_absolute():
    path = REPO / path
w = yaml.safe_load(path.read_text())
fileargs = {
    "nodeHost": "10.231.0.10",
    "nodeUser": "orbit",
    "nodeIdentityFile": "/etc/orbit/swamp-role-worker/ssh-identity",
    "become": True,
    "path": "${{ inputs.path }}",
    "ensure": "present",
    "content": '${{ vault.get("orbit-control-inputs", "qsecret-file") }}',
    "owner": "root",
    "group": "root",
    "mode": "0600",
}
w.update(
    vaults=["orbit-control-inputs"],
    queueTimeout=30,
    inputs={
        "type": "object",
        "properties": {
            "path": {"type": "string"},
            "method": {"type": "string", "enum": ["apply", "check"]},
        },
        "required": ["path", "method"],
    },
    jobs=[
        {
            "name": "file",
            "labels": {"role": "swamp"},
            "steps": [
                {
                    "name": "Converge private file",
                    "labels": {"role": "swamp"},
                    "writes": True,
                    "task": {
                        "type": "behavior",
                        "behaviorType": "@swamp/cfgmgmt/file",
                        "behaviorName": "proof-qsecret-file-target",
                        "globalArgs": fileargs,
                        "methodName": "${{ inputs.method }}",
                        "inputs": {},
                    },
                }
            ],
        }
    ],
)
path.write_text(yaml.safe_dump(w, sort_keys=False))
os.chown(path, 1000, 1000)
call(["workflow", "validate", name])
runs = []
start = time.monotonic()
subprocess.run(
    ["incus", "exec", "target", "--", "rm", "-f", "/etc/orbit/qsecret-proof.env"],
    check=True,
    timeout=20,
)
for i, marker in enumerate(markers):
    value = "QSECRET=" + marker + "\n"
    code = 'require "vendor/autoload.php"; $app=require "bootstrap/app.php"; $app->make(Illuminate\\Contracts\\Console\\Kernel::class)->bootstrap(); config(["swamp.run_token"=>trim(file_get_contents("/home/orbit/.orbit/swamp-proof/credentials/operator"))]); app(App\\Infrastructure\\Swamp\\SwampVaultExecutionCopy::class)->synchronize("orbit-control-inputs","qsecret-file",stream_get_contents(STDIN)); echo "stored";'
    put = subprocess.run(
        ["/usr/sbin/runuser", "-u", "orbit", "--", "php", "-r", code],
        input=value,
        text=True,
        capture_output=True,
        cwd=SOURCE / "apps/gateway",
        env=ENV,
        timeout=60,
        check=False,
    )
    clean(put.stdout + put.stderr, "native vault.put acknowledgement")
    assert put.returncode == 0, put.stdout + put.stderr
    # Check before apply exercises content drift and its changes array (the file diff).
    for method in ["check", "apply", "check"]:
        reply = call(
            ["workflow", "run", name, "--stdin"],
            body=json.dumps({"path": "/etc/orbit/qsecret-proof.env", "method": method}),
        )
        assert reply["status"] == "succeeded", json.dumps(reply)
        rid = reply.get("id") or reply.get("runId")
        runs.append(rid)
        call(["workflow", "history", "get", rid])
        call(["workflow", "history", "logs", rid])
        call(["workflow", "history", "outputs", rid])
    # Compare the actual target bytes without writing them to proof output/history.
    p = subprocess.run(
        ["incus", "exec", "target", "--", "cat", "/etc/orbit/qsecret-proof.env"],
        capture_output=True,
        timeout=20,
        check=False,
    )
    assert p.stdout.decode() == value, "Target content differs"
    p = subprocess.run(
        [
            "incus",
            "exec",
            "target",
            "--",
            "stat",
            "-c",
            "%a %U %G",
            "/etc/orbit/qsecret-proof.env",
        ],
        capture_output=True,
        text=True,
        timeout=20,
        check=False,
    )
    assert p.stdout.strip() == "600 root root", p.stdout
# Refused write: a directory cannot be replaced with a regular file. Its error/log path must stay clean too.
subprocess.run(
    ["incus", "exec", "target", "--", "mkdir", "-p", "/etc/orbit/qsecret-directory"],
    check=True,
    timeout=20,
)
reply = call(
    ["workflow", "run", name, "--stdin"],
    body=json.dumps({"path": "/etc/orbit/qsecret-directory", "method": "apply"}),
    ok=False,
)
assert reply.get("status") == "failed" or reply.get("returncode", 0) != 0, (
    "Directory write should fail"
)
call(["workflow", "history", "get", name])
call(["workflow", "history", "logs", name])
call(["workflow", "history", "outputs", name])
call(
    [
        "data",
        "query",
        'modelName == "proof-qsecret-file-target" && version >= 0',
        "--select",
        "content",
    ]
)
call(["behavior", "get", "proof-qsecret-file-target"])
for unit in ["orbit-swamp-serve", "orbit-node-worker"]:
    p = subprocess.run(
        ["journalctl", "-u", unit, "--no-pager", "-o", "cat"],
        capture_output=True,
        text=True,
        timeout=20,
        check=False,
    )
    clean(p.stdout + p.stderr, "journal " + unit)
# Also check sudo/sshd logs on the target for a content-bearing remote command.
p = subprocess.run(
    ["incus", "exec", "target", "--", "journalctl", "--no-pager", "-o", "cat"],
    capture_output=True,
    text=True,
    timeout=20,
    check=False,
)
clean(p.stdout + p.stderr, "target journal")
call(["vault", "delete", "orbit-control-inputs", "qsecret-file"])
print(
    json.dumps(
        {
            "question": "Q-SECRET",
            "proof_pass": True,
            "runtime": call(["--version"], ok=False),
            "source": subprocess.check_output(
                [
                    "/usr/sbin/runuser",
                    "-u",
                    "orbit",
                    "--",
                    "git",
                    "-C",
                    str(SOURCE),
                    "rev-parse",
                    "HEAD",
                ],
                text=True,
            ).strip(),
            "topology": "minimal@6",
            "runs": runs,
            "run_seconds": round(time.monotonic() - start, 1),
            "surfaces": seen,
            "file": {
                "mode": "0600",
                "owner": "root",
                "group": "root",
                "content_matches": True,
            },
            "scenarios": [
                "create",
                "change",
                "check drift",
                "check compliant",
                "refused directory write",
            ],
            "diff": "file changes array; pinned runtime has no separate data diff command",
        }
    )
)
