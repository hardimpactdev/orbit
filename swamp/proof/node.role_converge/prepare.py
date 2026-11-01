"""Select the sole role for a variant through the topology's normal CLI."""

import importlib.util
import json
import os
import re
import shlex
import sys
from pathlib import Path

ROOT = Path(__file__).resolve().parent
SPEC = importlib.util.spec_from_file_location("role_parity", ROOT / "parity.py")
PARITY = importlib.util.module_from_spec(SPEC)
SPEC.loader.exec_module(PARITY)


def precondition_path(role, run, directory):
    PARITY.require(role in PARITY.ROLES, "unsupported-role-variant")
    PARITY.require(
        re.fullmatch(r"[A-Za-z0-9_-]{1,96}", run) is not None, "invalid-proof-run"
    )
    return Path(directory) / ("precondition-" + run + "-" + role + ".json")


def prepare(role, node_id, run_cli):
    PARITY.require(
        role in PARITY.ROLES and type(node_id) is int and node_id > 0,
        "invalid-variant-target",
    )
    node = run_cli("node:show", str(node_id))
    PARITY.require(node["id"] == node_id, "unexpected-topology-node")
    assignments = run_cli("node:role:list", str(node_id))["assignments"]
    PARITY.require(len(assignments) <= 1, "unexpected-mixed-role-target")
    if role == "app-dev":
        # The topology owner enrolls this Node through canonical bootstrap.
        # Public reads hide its hash; admission and observer verification prove it.
        PARITY.require(
            node.get("status") == "active"
            and node.get("platform") == "linux"
            and len(assignments) == 1
            and assignments[0]["role"] == "app-dev"
            and assignments[0]["status"] == "active",
            "prepare-needs-bootstrapped-app-dev",
        )
    if assignments:
        current = assignments[0]
        PARITY.require(current["role"] in PARITY.ROLES, "refuse-unrelated-role-removal")
        if current["role"] != role:
            removal = run_cli(
                "node:role:remove", str(node_id), current["role"], "--force"
            )
            PARITY.require(
                removal["kind"] == "node.role_remove"
                and removal["status"] == "succeeded",
                "prepare-role-removal-failed",
            )
            assignments = []
    if not assignments:
        addition = run_cli("node:role:add", str(node_id), role)
        PARITY.require(
            addition["kind"] == "node.role_add" and addition["status"] == "succeeded",
            "prepare-role-add-failed",
        )
    assignments = run_cli("node:role:list", str(node_id))["assignments"]
    PARITY.require(len(assignments) == 1, "prepare-needs-sole-role")
    assignment = assignments[0]
    PARITY.require(
        assignment["role"] == role and assignment["status"] == "active",
        "prepare-role-not-active",
    )
    return {"node_id": node_id, "assignment_id": assignment["id"], "role": role}


if __name__ == "__main__":
    role, node = sys.argv[1:]
    prefix = shlex.split(os.environ["PROOF_EXEC"])
    result = prepare(role, int(node), lambda *args: PARITY.cli(prefix, *args))
    path = precondition_path(
        role, os.environ["PROOF_RUN"], os.environ["PROOF_KIND_DIR"]
    )
    path.write_text(json.dumps(result) + "\n")
    print(json.dumps(result))
