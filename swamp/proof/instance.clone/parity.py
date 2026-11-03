"""clone parity on the app-prod peer: the production account's home holds the candidate's committed source in
releases/initial and a private .env with the stored value, the release-layout marker deploy requires, the Process
installed but not started, the private preview Route on the clone Node, no fetch credential file, and the stored
value never in native history."""

import json
import os
import pathlib
import subprocess
import sys

operation = json.loads(pathlib.Path(os.environ["PROOF_OPERATION_FILE"]).read_text())
outputs = pathlib.Path(os.environ["PROOF_OUTPUTS"]).read_text()
run = os.environ["PROOF_RUN"]
project = operation["input"]["project_id"]
user = "orbit-app-" + str(project)
home = "/home/" + user
clone = home + "/releases/initial"


def target(*argv):
    return subprocess.run(
        ["incus", "exec", "peer", "--", *argv],
        capture_output=True,
        text=True,
        check=False,
    ).stdout.strip()


def check(condition, message):
    if not condition:
        sys.exit("parity: " + message)


check("proof-private-" not in outputs, "a stored value reached native history")
check(
    "proof-private-" + run in target("cat", home + "/.env"),
    "the stored value reached the clone .env",
)
check(
    target("stat", "-c", "%U %G %a", home + "/.env") == user + " orbit-env 640",
    "the clone .env belongs to the account and is readable by orbit-env",
)
mode = target("stat", "-c", "%U %a", home)
check(
    mode.startswith(user + " 7") and mode.endswith("0"),
    "the home is the account's directory, closed to others (Caddy passes by ACL): "
    + mode,
)
check(
    target("runuser", "-u", user, "--", "git", "-C", clone, "rev-parse", "HEAD")
    == operation["result"].get("commit"),
    "releases/initial is on the cloned commit",
)
check(
    target("sh", "-c", 'test -L "$1/.env" && readlink -f "$1/.env"', "sh", clone)
    == home + "/.env",
    "releases/initial/.env links to the home .env, as deploy requires",
)
marker = target(
    "sh",
    "-c",
    'tr "\\\\0" "|" < /var/lib/orbit/app-instance-sources/'
    + str(operation["target_id"])
    + "/release-layout",
)
check(
    marker.endswith("|" + user + "|" + home + "|initial|"),
    "the release-layout marker matches what deploy requires: " + marker,
)
check(
    target(
        "stat",
        "-c",
        "%U %a",
        "/var/lib/orbit/app-instance-sources/"
        + str(operation["target_id"])
        + "/release-layout",
    )
    == "root 600",
    "the marker is root-owned 0600",
)
units = target("sh", "-c", 'systemctl list-unit-files "orbit-process-*" --no-legend')
check("proof-worker" in units or units != "", "the Process unit is installed: " + units)
check(
    target("sh", "-c", 'systemctl is-active "orbit-process-*proof-worker*" || true')
    != "active",
    "the Process is not started before the first deploy",
)
check(
    operation["result"].get("commit") == operation["result"].get("candidate_commit"),
    "the clone commit equals the candidate commit",
)
check(
    target("sh", "-c", "ls /tmp/orbit-git-credential-* 2>/dev/null") == "",
    "the fetch credential file is removed",
)
preview = "k-" + run + ".prod.test"
check(
    isinstance(operation["input"].get("route_id"), int),
    "the clone admitted its preview Route",
)
check(
    target("sh", "-c", 'grep -rlF -- "$1" /etc/caddy', "sh", preview) != "",
    "the clone Node's Caddy serves the preview domain " + preview,
)
database = home + "/database.sqlite"
check(
    target("stat", "-c", "%U %a", database) == user + " 600",
    "the seeded database is the account's private file",
)
check(
    target(
        "python3",
        "-I",
        "-c",
        "import sqlite3,sys; print(sqlite3.connect(sys.argv[1]).execute('select v from proof').fetchone()[0])",
        database,
    )
    == "proof-row",
    "the seeded database holds the candidate's row",
)
check(
    'DB_DATABASE="' + database + '"' in target("cat", home + "/.env").splitlines(),
    "the clone .env names the seeded database",
)
check(
    target("sh", "-c", "ls /tmp/orbit-clone-sqlite-* 2>/dev/null") == "",
    "the snapshot files are removed",
)
print("parity: clone contract holds")
