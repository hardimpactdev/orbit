"""Remove one admitted source snapshot and its own seed receipt."""

from sqlite_clone_common import copy_database
from sqlite_seed_common import main, source_seed


def clean(source):
    if source["variant"] == "clone":
        copy_database({"operation": "cleanup", "directory": source["directory"]})
    else:
        source_seed(
            {
                **{
                    k: v
                    for k, v in source.items()
                    if k not in ("variant", "node_id", "target_instance_id")
                },
                "mode": "cleanup",
            }
        )
    return {"state": "cleaned"}


def apply(i):
    return clean(i["source"])


if __name__ == "__main__":
    main(apply)
