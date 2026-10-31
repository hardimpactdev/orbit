import base64
import json
import os

from bw import cli, configuration, emit


def write():
    config, intent = configuration()
    item = json.loads(os.environ["ORBIT_VAULT_ITEM"])
    wanted = next(
        row
        for row in config["wanted"]
        if row["source"] == item["source"] and row["fingerprint"] == item["fingerprint"]
    )
    cipher = wanted["cipher"]
    payload = {
        "type": 2,
        "organizationId": intent["organization_id"],
        "collectionIds": [intent["collection_id"]],
        "folderId": None,
        "name": cipher["name"],
        "notes": cipher["notes"],
        "secureNote": {"type": 0},
        "favorite": False,
        "reprompt": 0,
        "fields": [
            {
                "name": field["name"],
                "value": field["value"],
                "type": 1 if field["hidden"] else 0,
            }
            for field in cipher["fields"]
        ],
    }
    args = (
        ["edit", "item", item["cipher_id"]] if item["cipher_id"] else ["create", "item"]
    )
    result = json.loads(
        cli(*args, payload=base64.b64encode(json.dumps(payload).encode()))
    )
    return {
        "source": item["source"],
        "cipher_id": result["id"],
        "revision": result.get("revisionDate") or "",
        "fingerprint": item["fingerprint"],
    }


if __name__ == "__main__":
    emit(write)
