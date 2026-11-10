"""One native clone phase on its Node. Public intent is argv; private input is stdin."""

import datetime
import json
import os
import pwd
import shutil
import subprocess
import sys
from pathlib import Path


def main():
    container = json.loads(sys.argv[1])
    guest = sys.argv[2]
    account = sys.argv[3]
    folder = Path(sys.argv[4])
    phase = sys.argv[5]
    if phase not in {"container", "workspace", "agent", "t3", "sessions", "cleanup"}:
        raise ValueError("instance.clone_phase_invalid")
    name = container["name"]
    project = ["incus", "--project", container["project"]]
    changes = []
    secret = sys.stdin.read()

    def run(argv, content=None, check=True):
        result = subprocess.run(
            argv,
            check=False,
            input=content,
            text=True,
            capture_output=True,
            timeout=100,
            env=dict(os.environ, LC_ALL="C", GIT_TERMINAL_PROMPT="0"),
        )
        if check and result.returncode:
            # Tools may echo private input in stderr. Report the phase, never that output.
            raise RuntimeError("instance.container_" + phase + "_failed")
        return result

    def change(argv, content=None):
        result = run(argv, content)
        changes.append(argv[0])
        return result

    def private_folder():
        folder.mkdir(mode=0o700, parents=True, exist_ok=True)
        owner = pwd.getpwnam(account)
        os.chown(folder, owner.pw_uid, owner.pw_gid)
        os.chmod(folder, 0o700)
        return owner

    def inside(member, private=""):
        payload = ["python3", "-c", guest, member, json.dumps(container)]
        result = run(project + ["exec", name, "--"] + payload, private)
        return json.loads(result.stdout)

    if phase == "cleanup":
        shutil.rmtree(folder, ignore_errors=False) if folder.exists() else None
        print(json.dumps({"phase": phase, "changed": False}))
        return

    if phase == "container":
        owner = private_folder()
        credential = folder / "git-credential"
        credential.write_text(secret)
        credential.chmod(0o600)
        try:
            current = run(
                [
                    "incus",
                    "query",
                    f"/1.0/instances/{name}?project={container['project']}",
                ],
                check=False,
            )
            read = json.loads(current.stdout) if current.returncode == 0 else None
            acls = json.loads(
                run(
                    ["incus", "query", "/1.0/network-acls?project=default&recursion=1"]
                ).stdout
            )
            found = next((row for row in acls if row["name"] == container["acl"]), None)
            if found is None:
                change(
                    [
                        "incus",
                        "network",
                        "acl",
                        "create",
                        container["acl"],
                        "--project",
                        "default",
                    ]
                )
            if (
                found is None
                or {key: found.get(key) for key in ["description", "egress", "ingress"]}
                != container["acl_rules"]
            ):
                change(
                    [
                        "incus",
                        "network",
                        "acl",
                        "edit",
                        container["acl"],
                        "--project",
                        "default",
                    ],
                    json.dumps(container["acl_rules"]),
                )
            if read is None:
                clone = container["clone"]
                snapshots = json.loads(
                    run(
                        [
                            "incus",
                            "query",
                            f"/1.0/instances/{clone['source']}/snapshots?project={container['project']}&recursion=1",
                        ]
                    ).stdout
                )
                if clone["snapshot"] not in [row["name"] for row in snapshots]:
                    raise RuntimeError("instance.default_seed_moved")
                argv = project + [
                    "copy",
                    clone["source"] + "/" + clone["snapshot"],
                    name,
                ]
                for key, value in container["config"].items():
                    argv += ["--config", key + "=" + value]
                for key, value in {
                    "type": "disk",
                    "path": "/",
                    "pool": container["root_disk_pool"],
                    "size": container["root_disk_size"],
                }.items():
                    argv += ["--device", "root," + key + "=" + value]
                argv += [
                    "--device",
                    "eth0,ipv4.address=" + container["address"],
                    "--device",
                    "eth0,security.acls="
                    + container["network"]
                    + "-egress,"
                    + container["acl"],
                ]
                change(argv)
                read = json.loads(
                    run(
                        [
                            "incus",
                            "query",
                            f"/1.0/instances/{name}?project={container['project']}",
                        ]
                    ).stdout
                )
            t3 = container["clone"]["t3"]
            if t3["device"] not in read.get("devices", {}):
                change(
                    project
                    + [
                        "config",
                        "device",
                        "add",
                        name,
                        t3["device"],
                        "proxy",
                        "listen=tcp:" + t3["listen"] + ":" + str(t3["port"]),
                        "connect=tcp:"
                        + container["address"]
                        + ":"
                        + str(t3["internal_port"]),
                        "nat=true",
                    ]
                )
            if read["status"] != "Running":
                files = (
                    f"/1.0/instances/{name}/files?project={container['project']}&path="
                )
                own_units = {unit["name"] for unit in container.get("processes", [])}
                own_ids = {str(unit["id"]) for unit in container.get("processes", [])}
                remove = []
                for index, directory in enumerate(
                    [
                        "/etc/systemd/system/multi-user.target.wants",
                        "/etc/systemd/system",
                        "/etc/orbit/processes",
                    ]
                ):
                    response = run(["incus", "query", files + directory], check=False)
                    if response.returncode:
                        continue
                    for item in json.loads(response.stdout):
                        if (
                            (
                                index == 0
                                and item.startswith("orbit-")
                                and item.endswith(".service")
                            )
                            or (
                                index == 1
                                and item.startswith("orbit-process-")
                                and item.endswith(".service")
                                and item not in own_units
                            )
                            or (index == 2 and item not in own_ids)
                        ):
                            remove.append(name + directory + "/" + item)
                if remove:
                    change(project + ["file", "delete", "--force"] + remove)
                change(
                    project
                    + [
                        "file",
                        "push",
                        "--uid",
                        "0",
                        "--gid",
                        "0",
                        "--mode",
                        "0444",
                        "-",
                        name + "/etc/machine-id",
                    ],
                    read["config"]["volatile.uuid"].replace("-", "") + "\n",
                )
            for rule in container.get("firewall", []):
                argv = (
                    ["ufw"]
                    + (["route"] if rule.get("route") == "yes" else [])
                    + [rule["rule"], "in", "on", rule["interface_in"]]
                )
                if rule.get("interface_out"):
                    argv += ["out", "on", rule["interface_out"]]
                argv += [
                    "from",
                    rule["from_ip"],
                    "to",
                    rule["to_ip"],
                    "port",
                    rule["to_port"],
                    "proto",
                    rule["proto"],
                    "comment",
                    rule["comment"],
                ]
                result = run(argv)
                if "Rule added" in result.stdout or "Rules updated" in result.stdout:
                    changes.append("firewall")
            if read["status"] != "Running":
                change(project + ["start", name])
            # Git credentials remain on the Node, and leave even when a read/fetch fails.
            mirror = Path(container["mirror"])
            mirror.parent.mkdir(mode=0o700, parents=True, exist_ok=True)
            if not (mirror / "HEAD").exists():
                change(["git", "init", "--quiet", "--bare", str(mirror)])
            git = [
                "git",
                "-c",
                "core.hooksPath=/dev/null",
                "-c",
                "core.fsmonitor=false",
                "-C",
                str(mirror),
            ]
            remote_git = (
                [
                    "timeout",
                    "80",
                    "env",
                    "GIT_CONFIG_NOSYSTEM=1",
                    "GIT_CONFIG_GLOBAL=/dev/null",
                ]
                + git
                + [
                    "-c",
                    "credential.helper=",
                    "-c",
                    "credential.helper=store --file=" + str(credential),
                    "-c",
                    "http.followRedirects=false",
                    "-c",
                    "url.https://github.com/.insteadOf=git@github.com:",
                    "-c",
                    "url.https://github.com/.insteadOf=ssh://git@github.com/",
                    "-c",
                    "protocol.allow=never",
                    "-c",
                    "protocol." + container["protocols"][0] + ".allow=always",
                ]
            )
            branch = "refs/heads/" + container["branch"]
            remote = run(
                remote_git
                + ["ls-remote", "--heads", "--", container["repository"], branch]
            ).stdout.strip()
            before = run(
                git + ["rev-parse", "--verify", "--quiet", branch], check=False
            )
            if remote:
                run(
                    remote_git
                    + [
                        "fetch",
                        "--quiet",
                        "--no-tags",
                        "--",
                        container["repository"],
                        "+" + branch + ":" + branch,
                    ]
                )
            elif before.returncode:
                run(
                    git
                    + [
                        "fetch",
                        "--quiet",
                        "--no-tags",
                        "--",
                        container["clone"]["source_mirror"],
                        "+refs/heads/*:refs/seed/*",
                    ]
                )
                change(
                    git
                    + ["branch", container["branch"], container["clone"]["seed_commit"]]
                )
        finally:
            credential.unlink(missing_ok=True)
    elif phase == "workspace":
        # Native secret strings permit concatenation, not size/encoding. The run UUID separates two private values.
        run_id = folder.name.removeprefix(
            "orbit-clone-" + str(container["instance_id"]) + "-"
        )
        environment, process_json = secret.split("\n--orbit-" + run_id + "--\n")
        processes = json.loads(process_json)
        git = [
            "git",
            "-c",
            "core.hooksPath=/dev/null",
            "-c",
            "core.fsmonitor=false",
            "-C",
            container["mirror"],
        ]
        target = run(
            git + ["rev-parse", "--verify", "refs/heads/" + container["branch"]]
        ).stdout.strip()
        container["expected_commit"] = target
        bundle = folder / "workspace.bundle"
        container["bundle"] = "/tmp/orbit-workspace-" + folder.name + ".bundle"
        try:
            run(
                git
                + [
                    "bundle",
                    "create",
                    "--quiet",
                    str(bundle),
                    "refs/heads/" + container["branch"],
                ]
            )
            run(
                project
                + [
                    "file",
                    "push",
                    "--uid",
                    "1000",
                    "--gid",
                    "2760",
                    "--mode",
                    "0640",
                    str(bundle),
                    name + container["bundle"],
                ]
            )
            output = inside(
                "workspace",
                json.dumps({"environment": environment, "processes": processes}),
            )
            print("orbit-commit=" + target)
            print(json.dumps(output))
        finally:
            bundle.unlink(missing_ok=True)
        return
    elif phase in {"agent", "t3", "sessions"}:
        cached = folder / "t3.json"
        private = secret
        if phase == "sessions":
            private = cached.read_text() if cached.exists() else ""
        if phase in {"t3", "sessions"}:
            container["expected_commit"] = run(
                [
                    "git",
                    "-C",
                    container["mirror"],
                    "rev-parse",
                    "refs/heads/" + container["branch"],
                ]
            ).stdout.strip()
        output = inside(phase, private)
        reply = output.pop("private_reply", None)
        if reply is not None and not cached.exists():
            owner = private_folder()
            cached.write_text(json.dumps(reply))
            cached.chmod(0o600)
            os.chown(cached, owner.pw_uid, owner.pw_gid)
        print(json.dumps(output))
        return
    print(
        json.dumps(
            {
                "phase": phase,
                "changed": bool(changes),
                "completed_at": datetime.datetime.now(datetime.UTC).isoformat(),
            }
        )
    )


try:
    main()
except Exception as error:  # noqa: BLE001 - redact private subprocess failures at the command boundary.
    message = str(error)
    code = (
        message
        if message.startswith("instance.") and "\n" not in message
        else "instance.container_phase_failed"
    )
    print(code, file=sys.stderr)
    raise SystemExit(1)
