"""One container phase, entered once through incus exec. Never log private input."""

import http.client
import json
import os
import pathlib
import stat
import subprocess
import sys
import time
from contextlib import closing

# Mirrored by the Node boundary; the clone tests keep the two sets in sync.
GUEST_COMMANDS = ("curl", "git", "mkdir", "php", "python3", "systemctl", "t3")


def phase_main(phase, raw):
    c = json.loads(raw)
    changes = []
    ws = c["workspace"]
    root = pathlib.Path
    private = sys.stdin.read()

    def run(argv, check=True, agent=False, orbit=False, timeout=40):
        command = argv
        if agent or orbit:
            argv = ["runuser", "-u", "agent" if agent else "orbit", "--"] + argv
        r = subprocess.run(
            argv,
            check=False,
            capture_output=True,
            text=True,
            env=dict(
                os.environ,
                HOME="/home/agent" if agent else "/home/orbit" if orbit else "/root",
            ),
            cwd=ws if orbit else "/home/agent" if agent else "/",
            timeout=timeout,
        )
        if check and r.returncode:
            raise subprocess.CalledProcessError(r.returncode, command)
        return r

    def write(path, content, uid=0, gid=0, mode="0644"):
        p = root(path)
        p.parent.mkdir(mode=0o755, parents=True, exist_ok=True)
        b = content.encode()
        drift = not p.is_file() or p.read_bytes() != b
        if drift:
            p.write_bytes(b)
        s = p.stat()
        attrs = (s.st_uid, s.st_gid, stat.S_IMODE(s.st_mode)) != (
            uid,
            gid,
            int(mode, 8),
        )
        if attrs:
            os.chown(p, uid, gid)
            os.chmod(p, int(mode, 8))
        if drift or attrs:
            changes.append(path)

    def git(*args, agent=False, check=True):
        return run(
            ["git", "-c", "core.hooksPath=/dev/null", "-c", "core.fsmonitor=false"]
            + list(args),
            agent=agent,
            orbit=not agent,
            check=check,
        )

    if phase == "workspace":
        assert root(ws + "/.git").is_dir()
        before = git("symbolic-ref", "--quiet", "--short", "HEAD").stdout.strip()
        head = git("rev-parse", "HEAD").stdout.strip()
        try:
            if before != c["branch"] or head != c["expected_commit"]:
                git(
                    "fetch",
                    "--quiet",
                    c["bundle"],
                    "+refs/heads/"
                    + c["branch"]
                    + ":refs/remotes/origin/"
                    + c["branch"],
                )
                git(
                    "checkout",
                    "--quiet",
                    "-B",
                    c["branch"],
                    "refs/remotes/origin/" + c["branch"],
                )
                changes.append("checkout")
        finally:
            root(c["bundle"]).unlink(missing_ok=True)
        inputs = json.loads(private)
        php_settings = len(changes)
        if c["php"] is not None:
            # The same two settings as the container phase: the shared limit, and PHP Data Bridge from the runtime folder.
            write(
                "/etc/orbit/php/conf.d/99-orbit-runtime.ini",
                "memory_limit = 1G\n"
                "auto_prepend_file = /opt/orbit-runtime/current/php-data-bridge/bridge/prepend.php\n",
            )
        # A running unit read the old settings at its start.
        php_settings_changed = len(changes) > php_settings
        env = (
            root(c["environment_file"]).read_text()
            if root(c["environment_file"]).exists()
            else ""
        )
        updated = inputs["environment"]
        if env != updated:
            write(c["environment_file"], updated, 1000, 2760, "0640")
        # The existing Processes phase's group-write guarantee and foreign-owner cleanup.
        for directory, ds, fs in os.walk(ws):
            for name in ds + fs:
                p = root(directory) / name
                if p.is_symlink():
                    continue
                s = p.stat()
                mode = stat.S_IMODE(s.st_mode)
                wanted = mode | 0o060
                if p.is_dir() or mode & 0o111:
                    wanted |= 0o010
                if wanted != mode:
                    os.chmod(p, wanted)
                    changes.append("group-mode")
        own = {x["name"] for x in c["processes"]}
        own_ids = {str(x["id"]) for x in c["processes"]}
        removed_units = False
        for p in root("/etc/systemd/system").glob("orbit-process-*.service"):
            if p.name not in own:
                run(["systemctl", "disable", "--now", p.name])
                p.unlink()
                changes.append(str(p))
                removed_units = True
        process_root = root("/etc/orbit/processes")
        if process_root.is_dir():
            for folder in process_root.iterdir():
                if folder.name not in own_ids:
                    __import__("shutil").rmtree(folder)
                    changes.append(str(folder))
        for unit in c["processes"]:
            folder = process_root / str(unit["id"])
            target = unit.get("environment")
            if target and not root(target).is_file():
                folder.mkdir(mode=0o755, parents=True, exist_ok=True)
                write(target, inputs["processes"][target], 0, 0, "0600")
            if folder.is_dir():
                for old in folder.glob("*.env"):
                    if str(old) != target:
                        old.unlink()
                        changes.append(str(old))
        web_config = c.get("web_config")
        before_web_config = len(changes)
        if web_config is not None:
            write(web_config["path"], web_config["content"])
        web_config_changed = len(changes) > before_web_config
        node = run(["python3", "/usr/local/libexec/orbit-instance-node-runtime.py", "fetch", json.dumps(c)], timeout=100)
        if json.loads(node.stdout)["status"] == "converted":
            changes.append("node-runtime")
        desired = c["units"] + c["processes"]
        changed_units = []
        for unit in desired:
            n = len(changes)
            write("/etc/systemd/system/" + unit["name"], unit["content"])
            if len(changes) > n:
                changed_units.append(unit["name"])
        if changed_units or removed_units:
            run(["systemctl", "daemon-reload"])
        for unit in desired:
            name = unit["name"]
            if unit.get("desired", "running") != "running":
                continue
            if run(["systemctl", "is-enabled", "--quiet", name], False).returncode:
                run(["systemctl", "enable", name])
                changes.append("enable:" + name)
            active = (
                run(["systemctl", "is-active", "--quiet", name], False).returncode == 0
            )
            if (
                name in changed_units
                or php_settings_changed
                or (web_config_changed and name == "orbit-frankenphp.service")
                or not active
            ):
                run(["systemctl", "restart" if active else "start", name])
                changes.append("start:" + name)
    elif phase == "agent":
        for part in [".claude", ".t3/userdata"]:
            p = root("/home/agent") / part
            if not p.is_dir():
                run(["mkdir", "-p", "-m", "0700", str(p)], agent=True)
                changes.append(str(p))
        r = git(
            "config", "--global", "--get-all", "safe.directory", agent=True, check=False
        )
        if r.stdout.splitlines() != [ws]:
            git("config", "--global", "--replace-all", "safe.directory", ws, agent=True)
            changes.append("safe.directory")
        for k, v in [("user.name", "Orbit Agent"), ("user.email", "agent@orbit.local")]:
            r = git("config", "--global", "--get", k, agent=True, check=False)
            if r.returncode == 1:
                git("config", "--global", k, v, agent=True)
                changes.append(k)
        files = c["clone"]["files"] + [
            dict(c["clone"]["model_environment"], content=private)
        ]
        for f in files:
            value = f.get("content")
            if "json" in f:
                p = root(f["path"])
                old = json.loads(p.read_text()) if p.exists() else {}
                merged = old | f["json"]
                value = (
                    p.read_text()
                    if p.exists() and merged == old
                    else json.dumps(merged, separators=(",", ":")) + "\n"
                )
            write(f["path"], value, f["uid"], f["gid"], f["mode"])
        for f in c["clone"]["links"]:
            p = root(f["path"])
            if not p.is_symlink() or os.readlink(p) != f["target"]:
                p.unlink(missing_ok=True)
                p.symlink_to(f["target"])
                os.lchown(p, 2750, 2750)
                changes.append(str(p))
    elif phase == "t3":
        t = c["clone"]["t3"]
        active = (
            run(["systemctl", "is-active", "--quiet", t["unit"]], False).returncode == 0
        )
        if not active:
            run(["systemctl", "start", t["unit"]])
            changes.append("start:t3")
        ready = run(
            [
                "curl",
                "--fail",
                "--silent",
                "--show-error",
                "--max-time",
                "2",
                "--retry",
                "30",
                "--retry-delay",
                "1",
                "--retry-connrefused",
                "--retry-max-time",
                "30",
                "http://127.0.0.1:"
                + str(t["internal_port"])
                + "/.well-known/t3/environment",
            ],
            agent=True,
        )
        answered = time.time()
        environment = json.loads(ready.stdout)
        assert environment["environmentId"] and environment["serverVersion"]
        mark = root(t["project_mark"])
        if not mark.exists():
            r = run([t["t3"], "project", "add", ws, "--title", t["title"]], agent=True)
            write(str(mark), r.stdout.strip() + "\n", 2750, 2750, "0600")
        print(
            json.dumps(
                {
                    "phase": phase,
                    "changes": changes,
                    "ready_at": __import__("datetime")
                    .datetime.fromtimestamp(
                        answered, __import__("datetime").timezone.utc
                    )
                    .isoformat(),
                    "environment_id": environment["environmentId"],
                }
            )
        )
        return
    elif phase == "sessions":
        t = c["clone"]["t3"]
        url = "http://127.0.0.1:" + str(t["internal_port"])
        replies = []
        existing = json.loads(private) if private else None
        env = json.loads(
            run(
                ["curl", "--fail", "--silent", url + "/.well-known/t3/environment"],
                agent=True,
            ).stdout
        )
        for label in ["Orbit Gateway", "Orbit Swamp"]:
            token = (
                existing["gateway" if label == "Orbit Gateway" else "swamp"]["token"]
                if existing
                else run(
                    [
                        t["t3"],
                        "auth",
                        "session",
                        "issue",
                        "--label",
                        label,
                        "--token-only",
                    ],
                    agent=True,
                ).stdout.strip()
            )
            # This is the clone's T3 server, reached directly over loopback without a proxy.
            with closing(
                http.client.HTTPConnection("127.0.0.1", t["internal_port"], timeout=5)
            ) as connection:
                connection.request(
                    "GET",
                    "/api/auth/session",
                    headers={"Authorization": "Bearer " + token},
                )
                response = connection.getresponse()
                if response.status >= 400:
                    raise RuntimeError(
                        "instance.t3_session_http_" + str(response.status)
                    )
                body = json.load(response)
                assert body["authenticated"] and {
                    "access:read",
                    "access:write",
                }.issubset(body["scopes"])
                replies.append(
                    {
                        "token": token,
                        "status": response.status,
                        "body": json.dumps(body),
                    }
                )
        assert replies[0]["token"] != replies[1]["token"]
        assert git("symbolic-ref", "--short", "HEAD").stdout.strip() == c["branch"]
        assert git("rev-parse", "HEAD").stdout.strip() == c["expected_commit"]
        if c["php"] is not None:
            run(["php", "-v"], agent=True)
        for unit in c["units"] + c["processes"]:
            if unit.get("desired", "running") == "running":
                assert (
                    run(
                        ["systemctl", "is-active", "--quiet", unit["name"]], False
                    ).returncode
                    == 0
                )
        reply = {
            "environment": json.dumps(env),
            "gateway": replies[0],
            "swamp": replies[1],
        }
        print(
            json.dumps(
                {
                    "phase": phase,
                    "changes": [] if existing else ["sessions-issued"],
                    "checked_at": __import__("datetime")
                    .datetime.now(__import__("datetime").timezone.utc)
                    .isoformat(),
                    "private_reply": reply,
                }
            )
        )
        return
    elif phase == "check":
        assert git("symbolic-ref", "--short", "HEAD").stdout.strip() == c["branch"]
        assert git("rev-parse", "HEAD").stdout.strip() == c.get(
            "expected_commit", c["clone"]["seed_commit"]
        )
        assert git("config", "--global", "user.name", agent=True).stdout.strip()
        if c["php"] is not None:
            run(["php", "-v"], agent=True)
        for u in c["units"] + c["processes"]:
            assert (
                run(["systemctl", "is-active", "--quiet", u["name"]], False).returncode
                == 0
            )
    else:
        raise ValueError(phase)
    print(json.dumps({"phase": phase, "changes": changes}))


if __name__ == "__main__":
    try:
        phase_main(sys.argv[1], sys.argv[2])
    except subprocess.CalledProcessError as error:
        command = os.path.basename(error.cmd[0])
        print(
            "instance.container_"
            + sys.argv[1]
            + "_failed"
            + (
                ": command " + command + " exited " + str(error.returncode)
                if command in GUEST_COMMANDS
                else ""
            ),
            file=sys.stderr,
        )
        raise SystemExit(1)
    except Exception:  # noqa: BLE001 - private tool output must never reach native run history.
        print("instance.container_" + sys.argv[1] + "_failed", file=sys.stderr)
        raise SystemExit(1)
