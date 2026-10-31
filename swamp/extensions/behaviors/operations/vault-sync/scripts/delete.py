import json
import os

from bw import cli, emit


def delete():
    item = json.loads(os.environ["ORBIT_VAULT_ITEM"])
    cli("delete", "item", item["id"], "--permanent")
    return {"deleted": item["id"]}


if __name__ == "__main__":
    emit(delete)
