"""One native clone phase on its Node. Public intent is argv; private input is stdin."""

import datetime
import json
import os
import pwd
import re
import shutil
import subprocess
import sys
import time
from pathlib import Path

# Mirrored by the guest emitter; the clone tests keep the two sets in sync.
GUEST_COMMANDS = ("curl", "git", "mkdir", "php", "python3", "systemctl", "t3")



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
    if phase not in {"container", "club-key", "workspace", "t3", "sessions", "cleanup"}:
        raise ValueError("instance.clone_phase_invalid")
    name = container["name"]
    project = ["incus", "--project", container["project"]]
    changes = []
    # The Club key script's own word, which the club-key phase prints for the Operation's result.
    club_outcome = None
    workspace_deadline = time.monotonic() + 115 if phase == "workspace" else None
    secret = sys.stdin.read()

    def run(argv, content=None, check=True):
        remaining = 100 if workspace_deadline is None else workspace_deadline - time.monotonic()
        if remaining <= 0:
            raise subprocess.TimeoutExpired(argv, 115)
        result = subprocess.run(
            argv,
            check=False,
            input=content,
            text=True,
            capture_output=True,
            timeout=remaining,
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
        if member == "workspace":
            # Install the shipped helper outside the app-owned store. Its only store
            # entry runs as orbit inside the existing workspace launch below.
            change(project + ["file", "push", "--create-dirs", "--uid", "0", "--gid", "0",
                              "--mode", "0755", "-", name + "/usr/local/libexec/orbit-instance-node-runtime.py"],
                   json.loads(sys.argv[6])["node"])
        payload = ["python3", "-c", guest, member, json.dumps(container)]
        result = run(project + ["exec", name, "--"] + payload, private, check=False)
        if result.returncode:
            if member == 'sessions':
                try:
                    evidence = json.loads(result.stdout)
                except (ValueError, TypeError):
                    evidence = {}
                if (isinstance(evidence, dict) and evidence.get('error_code') == 'instance.container_process_inactive'
                        and isinstance(evidence.get('process_failure'), dict)):
                    # Only the guest's structured public receipt crosses; never its stderr.
                    public = {key: evidence[key] for key in ('error_code', 'process_failure') if key in evidence}
                    owner = private_folder()
                    receipt = folder / 't3.json'
                    receipt.write_text(json.dumps(public))
                    receipt.chmod(0o600)
                    os.chown(receipt, owner.pw_uid, owner.pw_gid)
                    raise RuntimeError(public['error_code'])
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
                links, remove = [], []
                for index, directory in enumerate(
                    [
                        "/etc/systemd/system/multi-user.target.wants",
                        "/etc/systemd/system",
                        "/etc/orbit/processes",
                        "/etc/systemd/system/sockets.target.wants",
                        "/etc/systemd/system/timers.target.wants",
                        "/etc/orbit/schedules",
                    ]
                ):
                    response = run(["incus", "query", files + directory], check=False)
                    if response.returncode:
                        if index in (1, 4, 5) and response.stderr.strip() not in (
                            "Error: file does not exist", "Error: not found", "Error: Not Found", "Error: Path not found"):
                            raise RuntimeError("schedule.container_clone_cleanup_failed")
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
                            or (index == 1 and item.startswith("orbit-schedule-") and item.endswith((".service", ".timer")))
                            or (index == 4 and item.startswith("orbit-schedule-") and item.endswith(".timer"))
                            or (index == 5 and item.endswith((".sh", ".complete")))
                            or (index == 2 and item not in own_ids)
                            or (index == 3 and (item in {"orbit-mail-1025.socket", "orbit-mail-2525.socket"}
                                               or item.startswith("orbit-node-service-")))
                            or (index == 1 and item.startswith("orbit-node-service-"))
                        ):
                            (links if directory.endswith(".wants") else remove).append(name + directory + "/" + item)
                # The enablement links go first and without --force: Incus 7.0.2 resolves the path for --force, so a link whose
                # unit file is already gone (this delete's own unit files, or an earlier failed clone) fails as "file does not exist".
                if links:
                    change(project + ["file", "delete"] + links)
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
                # Rule: only a copy that is not running gets these files; a running copy may already hold its own value from the
                # Node services apply below, which runs after the start and so writes last. An intent without the key has none.
                for item in container["clone"].get("boot_files", []):
                    change(project + ["file", "push", "--create-dirs", "--uid", str(item["uid"]), "--gid", str(item["gid"]),
                                      "--mode", item["mode"], "-", name + item["path"]], item["content"])
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
                ["python3", "-c", network_check, container["network"], "eth0"]
                + ([json.dumps(container["allowed_devices"]), json.dumps(container["config"])] if "allowed_devices" in container else []),
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
                    try:
                        evidence = json.loads(applied.stdout)
                        identity = evidence.get("node_service_target", {})
                        public = {key: value for key, value in identity.items() if key in {"id", "process_id", "port"}
                                  and type(value) is int and value > 0}
                        process_name = identity.get("process_name", "")
                        if isinstance(process_name, str) and re.fullmatch(r"[A-Za-z0-9][A-Za-z0-9_.-]{0,63}", process_name):
                            public["process_name"] = process_name
                        if code in {"process.target_inactive", "instance.host_unavailable", "instance.bridge_busy",
                                    "instance.node_service_port_in_use", "instance.node_service_unit_conflict",
                                    "instance.node_service_unreachable"} and evidence.get("error_code") == code and public:
                            print("orbit-node-service-failure=" + json.dumps({"error_code": code, "node_service_target": public}), file=sys.stderr)
                    except (ValueError, TypeError, AttributeError):
                        pass
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
    elif phase == "club-key":
        # The nested Gateway's Club key, from SSH stdin into the copy's /run, a tmpfs that no snapshot holds
        # (docs/reference/development-containers.md#the-club-key-of-the-nested-gateway). The value is only subprocess input.
        club = container["club_key"]
        if not club.get("available") or not secret:
            raise RuntimeError("instance.nested_club_key_delivery_failed")
        if run(project + ["exec", name, "--", "findmnt", "--noheadings", "--output", "FSTYPE", "--target", "/run"]).stdout.strip() != "tmpfs":
            raise RuntimeError("instance.nested_club_key_not_tmpfs")
        written = run(project + ["exec", name, "--", "sh", "-c", json.loads(sys.argv[6])["club_key"], "sh", club["directory"], club["file"],
                                 str(club["owner"]), str(club["group"]), club["tmpfiles"], club["gateway"], club["serve_unit"]], secret, check=False)
        outcome = written.stdout.strip()
        if written.returncode or outcome not in {"changed", "changed_serve_pending", "unchanged"}:
            raise RuntimeError("instance.nested_club_key_not_tmpfs" if written.returncode == 3 else "instance.nested_club_key_delivery_failed")
        if outcome != "unchanged":
            changes.append("club-key")
        club_outcome = outcome
    elif phase == "workspace":
        # Admission frames the private values and the clone's key names in one vault copy for native SSH stdin.
        workspace = json.loads(secret)
        environment = workspace["environment"]
        processes = workspace["processes"]
        # A value framed before the merge names existed has none; the guest then writes the render.
        merge = workspace.get("merge")
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
                json.dumps({"environment": environment, "processes": processes, "merge": merge}),
            )
            # The guest's commit is its first checkout's, so a retry never reports a later commit of the user.
            print("orbit-commit=" + output.pop("commit"))
            print(json.dumps(output))
        finally:
            bundle.unlink(missing_ok=True)
        return
    elif phase in {"t3", "sessions"}:
        cached = folder / "t3.json"

        def part(name):
            # The part that runs now, written before it starts, so a failure or a timeout leaves it for the create result:
            # clone-session-copy copies this file when clone-t3 fails. Success removes it before sessions reads the file.
            owner = private_folder()
            cached.write_text(json.dumps({"t3_part": name}))
            cached.chmod(0o600)
            os.chown(cached, owner.pw_uid, owner.pw_gid)

        def expected_commit():
            container["expected_commit"] = run(
                [
                    "git",
                    "-C",
                    container["mirror"],
                    "rev-parse",
                    "refs/heads/" + container["branch"],
                ]
            ).stdout.strip()

        if phase == "t3":
            # One step, two guest phases in order: the agent's files from the model key on stdin, then T3's first start, which
            # reads no private input. The step prints only T3's receipt; a failure names its own phase.
            part("agent")
            inside("agent", secret)
            part("t3")
            expected_commit()
            output = inside("t3", "")
            cached.unlink()
        else:
            expected_commit()
            output = inside(phase, cached.read_text() if cached.exists() else "")
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
                **({"club_key": club_outcome} if club_outcome is not None else {}),
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
