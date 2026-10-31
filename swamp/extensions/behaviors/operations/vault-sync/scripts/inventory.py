import json
import os

from bw import cli, configuration, emit


def inventory():
    _, intent = configuration()
    args = [
        "list",
        "items",
        "--organizationid",
        intent["organization_id"],
        "--collectionid",
        intent["collection_id"],
    ]
    if os.environ.get("ORBIT_VAULT_TRASH") == "true":
        args.append("--trash")
    items = json.loads(cli(*args))
    known = {item["source"] for item in intent["wanted"] + intent["ledger"]}
    rows = []
    for item in items:
        source = next(
            (
                field.get("value")
                for field in item.get("fields") or []
                if field.get("name") == "orbit.source"
            ),
            None,
        )
        rows.append(
            {
                "id": item["id"],
                "source": source if source in known else None,
                "organization_id": item.get("organizationId"),
                "collection_ids": item.get("collectionIds") or [],
                "revision": item.get("revisionDate"),
                "deleted": item.get("deletedDate") is not None,
            }
        )
    return rows


if __name__ == "__main__":
    emit(inventory)
