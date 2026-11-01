"""Prepare and check out the exact admitted Reverb revision."""

import websocket_directory
from phase_common import context, managed, ready

data, account = context()
websocket_directory.prepare(
    {"release": account["release"], "user": account["user"], "group": account["group"]}
)
git = ["/usr/bin/git", "-C", account["release"]]
managed(account, [*git, "init"])
managed(
    account,
    [
        *git,
        "fetch",
        "--depth",
        "1",
        data["runtime"]["repository"],
        data["runtime"]["ref"],
    ],
)
managed(account, [*git, "checkout", "--detach", "FETCH_HEAD"])
ready()
