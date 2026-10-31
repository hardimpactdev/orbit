"""Reconcile only the Router-owned LAN rule families through the canonical UFW transaction."""

import json
import os

import firewall as engine
from firewall import (
    Refusal,
    active,
    arguments,
    change,
    delete_comments,
    normalized_shape,
    ownership,
)

FAMILIES = [
    "orbit:router-lan-https",
    "orbit:router-lan-dns-udp",
    "orbit:router-lan-dns-tcp",
]


def router_comment(comment):
    return any(
        comment == prefix or comment.startswith(prefix + ":") for prefix in FAMILIES
    )


def router_inventory(rows):
    shapes = []
    for row in rows:
        if router_comment(row["comment"]):
            if row["shape"] is None:
                raise Refusal("node.firewall_convergence_failed")
            shapes.append(row["shape"])
    return shapes


def same_inventory(a, b):
    return sorted(json.dumps(s, sort_keys=True) for s in a) == sorted(
        json.dumps(s, sort_keys=True) for s in b
    )


def restore_router(snapshot):
    observed = router_inventory(active())
    comments = {s["comment"] for s in snapshot + observed}
    changed = {
        c
        for c in comments
        if not same_inventory(
            [s for s in snapshot if s["comment"] == c],
            [s for s in observed if s["comment"] == c],
        )
    }
    delete_comments(changed)
    for shape in snapshot:
        if shape["comment"] in changed:
            change(arguments(shape))
    if not same_inventory(snapshot, router_inventory(active())):
        raise Refusal("router.lan_ingress_recovery_failed")


def router_lan(payload):
    desired = [normalized_shape(s) for s in payload["desired"]]
    comments = [s["comment"] for s in desired]
    if (
        len(desired) > 4096
        or len(comments) != len(set(comments))
        or not all(router_comment(c) for c in comments)
    ):
        raise Refusal("firewall.input_invalid")
    rows = active()
    snapshot = router_inventory(rows)
    add = [s for s in desired if ownership(rows, s) == "missing"]
    replace = [s for s in desired if ownership(rows, s) == "drift"]
    remove = {s["comment"] for s in snapshot if s["comment"] not in comments}
    if not (payload["expand"] and (add or replace) or payload["prune"] and remove):
        return {"matches": True}
    if len(snapshot) != len({json.dumps(s, sort_keys=True) for s in snapshot}):
        raise Refusal("node.firewall_convergence_failed")
    try:
        if payload["expand"]:
            for shape in add:
                change(arguments(shape))
            delete_comments({s["comment"] for s in replace})
            for shape in replace:
                change(arguments(shape))
        if payload["prune"]:
            delete_comments(remove)
        observed = active()
        if payload["expand"] and any(
            ownership(observed, s) != "exact" for s in desired
        ):
            raise Refusal("node.firewall_convergence_failed")
        if payload["prune"] and any(
            s["comment"] not in comments for s in router_inventory(observed)
        ):
            raise Refusal("node.firewall_convergence_failed")
        return {"matches": True}
    except Exception:
        try:
            restore_router(snapshot)
        except Exception as error:
            raise Refusal("router.lan_ingress_recovery_failed") from error
        raise


def main(value):
    try:
        with engine.transaction_lock():
            return router_lan(value)
    except Refusal as error:
        return {"error_code": error.code}


if __name__ == "__main__":
    result = main(json.loads(os.environ["ORBIT_ROUTER_INPUT"]))
    print(json.dumps(result))
    if result.get("error_code"):
        raise SystemExit(1)
