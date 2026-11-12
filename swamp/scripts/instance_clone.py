"""One native clone phase on its Node. Public intent is argv; private input is stdin."""

import datetime
import json
import os
import pwd
import re
import shutil
import subprocess
import sys
from pathlib import Path

# Mirrored by the guest emitter; the clone tests keep the two sets in sync.
GUEST_COMMANDS = ("curl", "git", "mkdir", "php", "systemctl", "t3")



def select_branch(container, git, remote_git, run):
    """Populate the mirror from the admitted source; never use the seed for explicit selection."""
    selection = container["branch_selection"]
    target = "refs/heads/" + selection["target"]
    source = "refs/heads/" + (selection["base"] or selection["target"])
    remote = run(
        remote_git + ["ls-remote", "--heads", "--", container["repository"], source, target],
        check=False,
    )
    if remote.returncode:
        raise RuntimeError("instance.clone_source_unavailable")
    heads = {}
    for line in remote.stdout.splitlines():
        fields = line.split("\t")
        if len(fields) != 2 or not re.fullmatch(r"[0-9a-f]{40}(?:[0-9a-f]{24})?", fields[0]):
            raise RuntimeError("instance.clone_source_unavailable")
        heads[fields[1]] = fields[0]
    if selection["mode"] == "new" and target in heads:
        raise RuntimeError("instance.clone_branch_exists")
    if source not in heads:
        raise RuntimeError("instance.clone_branch_not_found")
    fetched = run(
        remote_git + ["fetch", "--quiet", "--no-tags", "--", container["repository"],
                      "+" + source + ":refs/orbit/clone-source"],
        check=False,
    )
    if fetched.returncode:
        raise RuntimeError("instance.clone_source_unavailable")
    # Fetch the named source, then retain the exact observed commit even when it advanced since admission.
    commit = selection["source_sha"]
    observed = run(git + ["cat-file", "-e", commit + "^{commit}"], check=False)
    if observed.returncode:
        raise RuntimeError("instance.clone_source_unavailable")
    run(git + ["update-ref", target, commit])


def main():
    container = json.loads(sys.argv[1])
    guest = sys.argv[2]
    network_check = sys.argv[3]
    account = sys.argv[4]
    folder = Path(sys.argv[5])
    phase = sys.argv[-1]
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
            raise RuntimeError(
                "instance.container_"
                + phase
                + "_failed: command "
                + Path(argv[0]).name
                + " exited "
                + str(result.returncode)
            )
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
        result = run(project + ["exec", name, "--"] + payload, private, check=False)
        if result.returncode:
            # Only the guest's complete, output-free diagnostic may cross this boundary.
            diagnostic = result.stderr.strip()
            if re.fullmatch(
                r"instance\.container_"
                + member
                + r"_failed: command (?:"
                + "|".join(GUEST_COMMANDS)
                + r") exited -?[0-9]+",
                diagnostic,
            ):
                raise RuntimeError(diagnostic)
            raise RuntimeError(
                "instance.container_"
                + member
                + "_failed: command incus exited "
                + str(result.returncode)
            )
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
                    + container["nic_acl"]
                    + ","
                    + container["acl"],
                    "--device",
                    "eth0,security.acls.default.egress.action=reject",
                    "--device",
                    "eth0,security.acls.default.ingress.action=reject",
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
                        "/etc/systemd/system/sockets.target.wants",
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
                            or (index == 3 and (item in {"orbit-mail-1025.socket", "orbit-mail-2525.socket"}
                                               or item.startswith("orbit-node-service-")))
                            or (index == 1 and item.startswith("orbit-node-service-"))
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
            effective = run(
                ["incus", "query", f"/1.0/instances/{name}?project={container['project']}"]
            ).stdout
            checked = run(
                ["python3", "-c", network_check, container["network"], "eth0"],
                effective,
                check=False,
            )
            if checked.returncode:
                raise RuntimeError("instance.container_network_unsafe")
            if read["status"] != "Running":
                change(project + ["start", name])
            if container.get("node_services"):
                bundle = json.loads(sys.argv[6])
                applied = run(["python3", "-c", bundle["node_services"],
                               json.dumps(container), json.dumps(bundle)], check=False)
                if applied.returncode:
                    code = applied.stderr.removesuffix("\n")
                    raise RuntimeError(code if code in {
                        "process.target_inactive", "instance.host_unavailable", "instance.bridge_busy",
                        "instance.node_service_port_in_use", "instance.node_service_unit_conflict",
                        "instance.node_service_unreachable"} else "instance.node_service_apply_failed")
            if container.get("mail") is not None:
                try:
                    bundle = json.loads(sys.argv[6])
                    attempted = run(["python3", "-c", bundle["mail"],
                                     json.dumps(container), json.dumps(bundle)], check=False)
                    if attempted.returncode:
                        code = attempted.stderr.removesuffix("\n")
                        mail = {"status": "failed", "reason": code if code in
                                {"instance.host_unavailable", "process.target_inactive"} else "instance.mail_update_failed"}
                    else:
                        mail = json.loads(attempted.stdout)
                        if mail.get("status") not in {"applied", "unchanged", "skipped"} or (
                            mail["status"] == "skipped" and mail.get("reason") not in {"mail_port_in_use", "mail_unit_conflict"}
                        ):
                            raise ValueError("invalid mail outcome")
                except Exception:  # noqa: BLE001 - mail is advisory; keep arbitrary tool output private.
                    mail = {"status": "failed", "reason": "instance.mail_update_failed"}
                print("orbit-mail=" + mail["status"] + (":" + mail["reason"] if "reason" in mail else ""))
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
            if "branch_selection" in container:
                select_branch(container, git, remote_git, run)
            else:
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
        # Admission frames both private values in one vault copy for native SSH stdin.
        workspace = json.loads(secret)
        environment = workspace["environment"]
        processes = workspace["processes"]
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
