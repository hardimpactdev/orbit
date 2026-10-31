"""Resolve the submitted create once; native resume retries only this public observation."""

from upcloud_common import Refused, intent, inventory, run


def action():
    result = inventory(intent())
    if not result["existing_id"]:
        raise Refused()
    return {"provider_id": result["existing_id"]}


if __name__ == "__main__":
    run(action)
