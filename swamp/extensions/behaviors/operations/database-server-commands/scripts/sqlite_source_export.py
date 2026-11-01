"""Create one admitted consistent source snapshot; return public metadata only."""

from sqlite_clone_common import copy_database
from sqlite_seed_common import main, source_seed


def apply(i):
    source = i["source"]
    if source["variant"] == "clone":
        result = copy_database(
            {
                "operation": "export",
                "checkout": source["checkout"],
                "source": source["source"],
                "directory": source["directory"],
            }
        )
    else:
        result = source_seed(
            {
                **{
                    k: v
                    for k, v in source.items()
                    if k not in ("variant", "node_id", "target_instance_id")
                },
                "mode": "prepare",
            }
        )
    return {k: v for k, v in result.items() if k in ("bytes", "sha256", "mode")}


if __name__ == "__main__":
    main(apply)
