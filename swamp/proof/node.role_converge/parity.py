"""Read terminal role state through the topology operator and its workload Node."""

import importlib.util
import json
import os
import re
import shlex
import subprocess
import sys
from pathlib import Path

ROLES = {"database", "ingress", "app-prod", "websocket"}
ROOT = Path(__file__).resolve().parents[2]


def require(condition, message):
    if not condition:
        raise ValueError(message)


def topology_command(prefix, node, argv):
    result = subprocess.run(
        [*prefix, node, "--timeout=60", "--argv=" + json.dumps(argv)],
        check=True,
        capture_output=True,
        text=True,
        timeout=75,
    )
    return json.loads(result.stdout)


def cli(prefix, *argv):
    return topology_command(prefix, "operator", ["orbit", *argv, "--json"])


def public_receipt(operation, role, outputs):
    require(operation["kind"] == "node.role_converge", "unexpected-operation-kind")
    require(operation["status"] == "succeeded", "operation-not-succeeded")
    intent = operation["input"]["native_role"]
    result = operation["result"]
    require(intent["role"] == role, "unexpected-admitted-role")
    for key in ["node_id", "assignment_id", "role", "created"]:
        require(result[key] == intent[key], "projection-identity-" + key)
        require(outputs[key] == result[key], "native-projection-" + key)
    require(result["created"] is False, "convergence-created-assignment")
    for key in [
        "role_proven",
        "runtime_proven",
        "certificates_proven",
        "publication_proven",
    ]:
        require(result[key] is True and outputs[key] is True, "missing-proof-" + key)
    require(result["footprint"]["footprint_proven"] is True, "footprint-not-proven")
    require(outputs["footprint"] == result["footprint"], "native-projection-footprint")
    require(result["follow_up"] is None, "unexpected-follow-up")
    require("error_code" not in result["cli"], "cli-follow-up")
    required = {
        item["name"] for item in intent["footprint"]["artifacts"] if item["required"]
    }
    applied = {
        item["name"]
        for item in result["footprint"]["artifacts"]
        if item["status"] == "applied"
    }
    require(required <= applied, "required-footprint-not-applied")
    return intent


# The target check reads public files, unit state and local listeners only.
HOST_CHECK = r"""
import hashlib,json,pathlib,socket,stat,subprocess,sys
data=json.loads(sys.argv[1]);intent=data['intent'];role=intent['role']
def require(value,message):
 if not value:raise ValueError(message)
def command(argv):
 return subprocess.run(argv,check=True,capture_output=True,text=True,timeout=20).stdout
def active(unit):command(['/usr/bin/systemctl','is-active','--quiet',unit])
binary=pathlib.Path('/usr/local/bin/orbit-agent')
with binary.open('rb') as stream:require(hashlib.file_digest(stream,'sha256').hexdigest()==intent['agent_sha256'],'observer-binary-pin')
active('orbit-agent.service')
for path in ['/etc/orbit/agent/config.toml','/etc/orbit/agent/ca.pem']:
 require(pathlib.Path(path).is_file(),'observer-public-file-missing')
if role=='database':command(['/usr/bin/docker','--version'])
if role in ['ingress','app-prod','websocket']:
 active('caddy.service')
 command(['/usr/bin/caddy','validate','--config','/etc/caddy/Caddyfile','--adapter','caddyfile'])
 expected=data['caddy_content']
 require(pathlib.Path('/etc/caddy/Caddyfile').read_text()==expected,'caddy-catalog-differs')
if role=='app-prod':
 for version in intent['php']['versions']:
  command(['/usr/bin/php'+version,'--version'])
 command(['/usr/bin/composer','--version','--no-ansi'])
if role=='websocket':
 active('orbit-websocket.service')
 runtime=intent['runtime'];link=pathlib.Path(runtime['install_path'])
 require(link.is_symlink(),'websocket-release-not-linked')
 revision=command(['/usr/bin/git','-c','safe.directory='+str(link.resolve()),'-C',str(link),'rev-parse','HEAD']).strip()
 require(revision==runtime['ref'],'websocket-revision-differs')
 environment=link/'.env'
 require(stat.S_IMODE(environment.stat().st_mode)==0o600,'websocket-environment-mode')
 with socket.create_connection(('127.0.0.1',runtime['port']),timeout=3):pass
 certificate=data['certificate']
 require(hashlib.sha256(pathlib.Path(certificate['root']).read_bytes()).hexdigest()==certificate['root_hash'],'admitted-root-differs')
 command(['/usr/bin/openssl','verify','-CAfile',certificate['root'],'-verify_hostname','reverb.orbit',certificate['leaf']])
print(json.dumps({'host_proven':True,'role':role,'observer_pin':intent['agent_sha256']}))
"""


def role_certificate(intent):
    path = ROOT / "extensions/behaviors/operations/certificates/scripts/role_paths.py"
    spec = importlib.util.spec_from_file_location("canonical_role_paths", path)
    module = importlib.util.module_from_spec(spec)
    spec.loader.exec_module(module)
    leaves = intent["certificates"]["leaves"]
    require(
        len(leaves) == 1
        and leaves[0]["domain"] == "reverb.orbit"
        and leaves[0]["scope"] == "role-websocket",
        "unexpected-websocket-leaf",
    )
    certificate, _, root = module.role_paths(leaves[0])
    return {
        "leaf": str(certificate),
        "root": str(root),
        "root_hash": leaves[0]["root_hash"],
    }


def target_publication(intent):
    if intent["role"] == "websocket":
        rows = [
            row for row in intent["publications"] if row["node_id"] == intent["node_id"]
        ]
        require(len(rows) == 1, "missing-websocket-target-publication")
        return rows[0]["after"]["caddy"]["content"]
    return intent["caddy"]["caddy"]["content"]


def main(role):
    require(role in ROLES, "unsupported-role-variant")
    operation_id = os.environ["PROOF_OPERATION_ID"]
    require(operation_id.isdecimal() and int(operation_id) > 0, "invalid-operation-id")
    prefix = shlex.split(os.environ["PROOF_EXEC"])
    require(bool(prefix), "missing-topology-exec")
    operation = cli(prefix, "operation:show", operation_id)
    outputs = json.loads(Path(os.environ["PROOF_OUTPUTS"]).read_text())
    intent = public_receipt(operation, role, outputs)
    run = os.environ["PROOF_RUN"]
    require(re.fullmatch(r"[A-Za-z0-9_-]{1,96}", run) is not None, "invalid-proof-run")
    before = json.loads(
        (
            Path(os.environ["PROOF_KIND_DIR"])
            / ("precondition-" + run + "-" + role + ".json")
        ).read_text()
    )
    require(
        before == {key: intent[key] for key in ["node_id", "assignment_id", "role"]},
        "assignment-changed-after-precondition",
    )
    assignments = cli(prefix, "node:role:list", str(intent["node_id"]))["assignments"]
    require(len(assignments) == 1, "contribution-needs-sole-role")
    assignment = assignments[0]
    require(
        assignment["id"] == intent["assignment_id"] and assignment["role"] == role,
        "recorded-assignment-differs",
    )
    require(
        assignment["status"] == "active" and assignment["error_code"] is None,
        "assignment-not-active",
    )
    # Keep the target check below argv limits; recipe archives and full public
    # admission plans are checked through the operator, not sent to the Node.
    target_intent = {key: intent[key] for key in ["role", "agent_sha256"]}
    if role == "app-prod":
        target_intent["php"] = {"versions": intent["php"]["versions"]}
    if role == "websocket":
        target_intent["runtime"] = {
            key: intent["runtime"][key] for key in ["install_path", "ref", "port"]
        }
    descriptor = {"intent": target_intent}
    if role in ["ingress", "app-prod", "websocket"]:
        descriptor["caddy_content"] = target_publication(intent)
    if role == "websocket":
        descriptor["certificate"] = role_certificate(intent)
    observed = topology_command(
        prefix,
        "app-prod",
        ["sudo", "-n", "python3", "-I", "-c", HOST_CHECK, json.dumps(descriptor)],
    )
    require(
        observed["host_proven"] is True and observed["role"] == role, "host-not-proven"
    )
    print(
        json.dumps(
            {
                "role": role,
                "assignment_id": assignment["id"],
                "host": observed,
                "parity": "passed",
            }
        )
    )


if __name__ == "__main__":
    main(sys.argv[1])
