"""Read-only comparison of served behavior versions with disk source versions."""

import concurrent.futures
import pathlib


def inventory(app, changed=None):
    return current_source_module().inventory(app, changed)


def current_source_module():
    import importlib.util

    path = pathlib.Path(__file__).with_name("current_source_inventory.py")
    spec = importlib.util.spec_from_file_location("current_source_inventory", path)
    module = importlib.util.module_from_spec(spec)
    spec.loader.exec_module(module)
    return module


def coverage(rows, served_types):
    return current_source_module().coverage(rows, served_types)


def audit(app, describe, changed=None, rows=None):
    rows = inventory(app, changed) if rows is None else rows
    output = {
        "checked": len(rows),
        "matched": 0,
        "mismatches": [],
        "errors": [],
        "versions": [],
        "scope": "disk-backed indexed behaviors; built-ins have no disk bundle",
        "limit": "Version equality does not prove executed JavaScript bytes or same-version helper freshness.",
    }

    def check(row):
        if row.get("error"):
            return row
        try:
            return {**row, "served": describe(row["type"])}
        except Exception as exc:  # noqa: BLE001 -- An incomplete audit cannot be accepted.
            return {**row, "error": type(exc).__name__}

    with concurrent.futures.ThreadPoolExecutor(max_workers=4) as pool:
        for item in pool.map(check, rows):
            if item.get("error"):
                output["errors"].append(item)
            elif item["served"] != item["expected"]:
                output["mismatches"].append(item)
            else:
                output["matched"] += 1
            output["versions"].append(item)
    return output
