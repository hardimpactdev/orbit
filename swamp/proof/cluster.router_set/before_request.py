"""Capture both Router baselines after normal preparation and before admission."""

import json
import os
import runpy
import sys
from pathlib import Path

api = runpy.run_path(str(Path(os.environ["PROOF_KIND_DIR"]) / "support.py"))
(
    variant,
    cluster_id,
    route_id,
    domain,
    old_id,
    new_id,
    old_role,
    new_role,
    gateway_id,
    app_id,
) = sys.argv[1:]
orbit = api["orbit"]
old = orbit("node:show", old_id)
new = orbit("node:show", new_id)
assert old["id"] == int(old_id) and new["id"] == int(new_id)
assert old["cluster_id"] == new["cluster_id"] == int(cluster_id)
assert orbit("cluster:show", cluster_id)["router"]["id"] == old["id"]
route = orbit("route:show", route_id)
assert route["id"] == int(route_id) and route["domain"] == domain
views = {}
for role, node in [(old_role, old), (new_role, new)]:
    view = api["read_node"](
        role, domain, https_address=old["wireguard_ip"] if role == old_role else None
    )
    api["assert_selected"](view, domain, old, serves=role == old_role)
    views[role] = view
gateway = orbit("node:show", gateway_id)
app = orbit("node:show", app_id)
assert gateway["id"] == int(gateway_id) and app["id"] == int(app_id)
for role in ["gateway", "app-dev"]:
    views[role] = api["read_node"](role, domain)
context = {
    "gateway": {
        key: gateway[key] for key in ("id", "cluster_id", "wireguard_ip", "lan_ip")
    },
    "app": {key: app[key] for key in ("id", "cluster_id", "wireguard_ip", "lan_ip")},
    "cluster_id": int(cluster_id),
    "route_id": int(route_id),
    "domain": domain,
    "old": {key: old[key] for key in ("id", "cluster_id", "wireguard_ip", "lan_ip")},
    "new": {key: new[key] for key in ("id", "cluster_id", "wireguard_ip", "lan_ip")},
    "old_role": old_role,
    "new_role": new_role,
    "views": views,
}
api["baseline_path"](variant).write_text(json.dumps(context))
