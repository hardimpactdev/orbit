"""Read and validate the one open PR for the admitted branch and repository."""

import urllib.parse

from publication_common import intent, request
from repository_common import Refusal, emit


def read():
    value = intent()
    head = urllib.parse.quote(
        value["repository"].split("/")[0] + ":" + value["branch"], safe=""
    )
    rows = request(
        "/repos/"
        + value["repository"]
        + "/pulls?state=open&head="
        + head
        + "&per_page=100"
    )
    matches = [
        row
        for row in rows
        if row["head"]["ref"] == value["branch"]
        and row["head"]["repo"]["full_name"].lower() == value["repository"].lower()
    ]
    if len(matches) > 1:
        raise Refusal("tasks.publish_failed")
    if not matches:
        return {"number": None}
    row = matches[0]
    if (
        row["state"] != "open"
        or row["base"]["ref"] != value["base"]
        or row["head"]["sha"] != value["commit"]
    ):
        raise Refusal("tasks.head_moved")
    return {"number": row["number"]}


if __name__ == "__main__":
    emit(read)
