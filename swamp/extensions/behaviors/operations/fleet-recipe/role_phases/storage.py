"""Validate the effective admitted apps root before the canonical storage action."""

import json

from phase_common import account, inputs, module


def converge(data, observed):
    intent = data["intent"]["storage"]
    home = observed["home"]
    root = intent["root"] or home + "/apps"
    inside = lambda path, parent: path.startswith(parent.rstrip("/") + "/")
    if (
        observed["user"] != intent["user"]
        or root == home
        or inside(home, root)
        or (inside(root, home) and root[len(home) + 1 :].split("/")[0].startswith("."))
        or any(
            root == other or inside(root, other) or inside(other, root)
            for other in intent["protected_roots"]
        )
        or any(
            root == other or inside(root, other)
            for other in intent["managed_checkouts"]
        )
    ):
        raise ValueError("node.storage_root_unusable")
    outcome = module("storage_root").main(
        {
            "root": root,
            "user": observed["user"],
            "group": observed["group"],
            "create_missing": intent["create_missing"],
        }
    )
    if "error_code" in outcome:
        raise ValueError(outcome["error_code"])
    return {"ready": True, "root": root}


if __name__ == "__main__":
    print(json.dumps(converge(inputs(), account())))
