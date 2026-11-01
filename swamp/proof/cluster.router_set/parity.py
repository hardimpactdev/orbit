"""Require successful cutover and exact public serving state before the return."""

import json
import os
import runpy
import sys
from pathlib import Path

api = runpy.run_path(str(Path(os.environ["PROOF_KIND_DIR"]) / "support.py"))
context = json.loads(api["baseline_path"](sys.argv[1]).read_text())
outputs = json.loads(Path(os.environ["PROOF_OUTPUTS"]).read_text())
native = json.loads(outputs["stdout"])
operation = api["orbit"]("operation:show", os.environ["PROOF_OPERATION_ID"])
api["check_terminal"](operation, native, context)
assert (
    api["orbit"]("cluster:show", context["cluster_id"])["router"]["id"]
    == context["new"]["id"]
)
intent = operation["input"]
assert any(
    leaf["node_id"] == context["new"]["id"]
    and leaf["scope"] == "route-" + str(context["route_id"]) + "-router"
    and leaf["domain"] == context["domain"]
    for leaf in intent["route_mutations"]["forward"]["prepare_certificates"]
), "The candidate must prepare the proof Route certificate"
nodes = {
    context["old_role"]: context["old"],
    context["new_role"]: context["new"],
    "gateway": context["gateway"],
    "app-dev": context["app"],
}
roles = {node["id"]: role for role, node in nodes.items()}
views = {}
for publication in intent["route_mutations"]["forward"]["publications"]:
    assert publication["node_id"] in roles, (
        "Publication is outside the declared topology"
    )
    role = roles[publication["node_id"]]
    node = nodes[role]
    leaves = [
        leaf
        for leaf in intent["route_mutations"]["forward"]["prepare_certificates"]
        if leaf["node_id"] == node["id"]
    ]
    cleanup = [
        item
        for item in intent["route_mutations"]["forward"]["cleanup"]
        if item["node_id"] == node["id"]
    ]
    view = api["read_node"](
        role,
        context["domain"],
        leaves,
        cleanup=cleanup,
        https_address=node["wireguard_ip"] if role == context["new_role"] else None,
    )
    if role in {context["old_role"], context["new_role"]}:
        api["assert_selected"](
            view, context["domain"], context["new"], serves=role == context["new_role"]
        )
    api["assert_publication"](
        publication,
        intent["route_mutations"]["artifacts"],
        context["views"][role],
        view,
    )
    if view["active"]:
        assert view["loaded_matches_disk"]
    for leaf, observed in zip(leaves, view["leaves"], strict=True):
        assert observed == {
            "scope": leaf["scope"],
            "root_hash": leaf["root_hash"],
            "verified": True,
        }
    assert view["cleanup"] == [
        {"scope": item["scope"], "absent": True} for item in cleanup
    ]
    views[role] = view
assert context["old_role"] in views and context["new_role"] in views
assert sum(len(view["leaves"]) for view in views.values()) == len(
    intent["route_mutations"]["forward"]["prepare_certificates"]
)
print(
    json.dumps(
        {
            "operation_id": operation["id"],
            "selected_node_id": context["new"]["id"],
            "restored": False,
            "cleanup_complete": True,
            "readback": views,
        }
    )
)
