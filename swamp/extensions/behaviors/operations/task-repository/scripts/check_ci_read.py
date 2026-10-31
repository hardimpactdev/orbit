"""One bounded GitHub Checks read per scheduled check; never wait or paginate."""

import json
import os

from publication_common import request
from repository_common import Refusal, emit


def observe():
    pending = json.loads(os.environ["ORBIT_PENDING_CHECK"])
    value = pending["inputs"]["intent"]
    try:
        response = request(
            "repos/"
            + value["repository"]
            + "/commits/"
            + pending["result"]["commit"]
            + "/check-runs?filter=latest&per_page=100"
        )
    except Refusal:
        raise Refusal("tasks.handoff_ci_unavailable") from None

    rows = response["check_runs"]
    if (
        response["total_count"] > 100
        or not isinstance(rows, list)
        or len(rows) != response["total_count"]
    ):
        raise Refusal("tasks.handoff_ci_unavailable")
    return {
        "runs": [
            {
                "id": row["id"],
                "name": row["name"][:256],
                "head_sha": row["head_sha"],
                "status": row["status"],
                "conclusion": row["conclusion"],
                "app": {"slug": row["app"]["slug"]},
            }
            for row in rows
        ]
    }


if __name__ == "__main__":
    emit(observe)
