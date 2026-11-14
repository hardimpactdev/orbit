"""One container phase, entered once through incus exec. Never log private input."""

import http.client
import json
import os
import pathlib
import re
import stat
import subprocess
import sys
import time
from concurrent.futures import ThreadPoolExecutor
from contextlib import closing

# Mirrored by the Node boundary; the clone tests keep the two sets in sync.
GUEST_COMMANDS = ("curl", "git", "mkdir", "php", "python3", "systemctl", "t3")

# Written by root after this Instance's first checkout; outside the workspace, so the agent cannot write it.
CHECKOUT_MARK = "/var/lib/orbit/workspace-checkout"

# The names a task copy's cleaning reports of each kind; the counts give the rest.
CLEANED_NAMES = 20
# The most clean rounds of a task copy: one per untracked `.gitignore` nested in another untracked folder that it hides.
CLEAN_ROUNDS = 64

# The two T3 sessions a clone registers, in the order the registration reads them (conn/environment-renew.mmd).
SESSIONS = (("gateway", "Orbit Gateway"), ("swamp", "Orbit Swamp"))

# The agent's own ignore file: the task check runs Git as `agent` with no global configuration, so Git reads this file by default.
AGENT_IGNORE = "/home/agent/.config/git/ignore"


class PhaseRefusal(AssertionError):
    def __init__(self, evidence):
        super().__init__(evidence['error_code'])
        self.evidence = evidence


def process_failure(unit, state):
    """Public classes and exit facts only; a Process journal can contain arbitrary secrets."""
    classes = {
        'ActiveState': {'active', 'inactive', 'failed', 'activating', 'deactivating', 'reloading', 'maintenance'},
        'SubState': {'running', 'exited', 'dead', 'failed', 'start', 'start-pre', 'start-post', 'auto-restart', 'auto-restart-queued', 'stop', 'stop-post', 'stop-sigterm', 'stop-sigkill'},
        'Result': {'success', 'resources', 'timeout', 'exit-code', 'signal', 'core-dump', 'watchdog', 'start-limit-hit', 'protocol', 'oom-kill', 'exec-condition'},
    }
    value = {'unit': unit['name']}
    if type(unit.get('id')) is int:
        value['process_id'] = unit['id']
    for key, allowed in classes.items():
        value[key] = state.get(key) if state.get(key) in allowed else 'unknown'
    for key in ('ExecMainStatus', 'NRestarts'):
        raw = state.get(key, '')
        value[key] = int(raw) if re.fullmatch(r'[0-9]{1,10}', raw) and int(raw) <= 2147483647 else None
    return {'error_code': 'instance.container_process_inactive', 'process_failure': value}


# The key names phpdotenv reads (vlucas/phpdotenv EntryParser), so no key it loads hides as a non-key line.
ENV_LINE = re.compile(r"\s*(?:export\s+)?([A-Za-z0-9_.]+)\s*=(.*)")

# A credential value shorter than this is matched only as a whole value, never as a part of one.
CONTAINED_CREDENTIAL = 8


def env_blocks(text):
    """Split a `.env` into (key or None, raw text, value) blocks; a quoted value may span lines. Raw text stays as written."""
    blocks = []
    lines = text.splitlines(keepends=True)
    i = 0
    while i < len(lines):
        found = ENV_LINE.fullmatch(lines[i].rstrip("\r\n"))
        if not found:
            blocks.append((None, lines[i], None))
            i += 1
            continue
        raw = lines[i]
        rest = found.group(2).lstrip()
        quote = rest[:1] if rest[:1] in {'"', "'"} else ""
        value = re.split(r"\s+#", rest, maxsplit=1)[0].strip()
        if quote:
            body, end = rest[1:], i
            closing = closing_quote(body, quote)
            while closing is None and end + 1 < len(lines):
                end += 1
                body += "\n" + lines[end].rstrip("\r\n")
                closing = closing_quote(body, quote)
            # An unclosed quote takes only its own line, so no later key hides inside it.
            if closing is not None:
                raw = "".join(lines[i : end + 1])
                i = end
                value = body[:closing]
                if quote == '"':
                    value = re.sub(r'\\(["\\])', r"\1", value)
        blocks.append((found.group(1), raw, value))
        i += 1
    return blocks


def closing_quote(body, quote):
    """The index of the quote that closes a value, or None. In double quotes a backslash escapes the next character."""
    escaped = False
    for index, char in enumerate(body):
        if quote == '"' and not escaped and char == "\\":
            escaped = True
            continue
        if char == quote and not escaped:
            return index
        escaped = False
    return None


def merge_environment(base, rendered, merge):
    """
    A clone's `.env`: its default's file from the seed copy (`base`), with the keys the clone owns taken from the Gateway's render,
    the default's connection keys and the Project's dropped keys removed, and every other key whose value is one of the default's
    connection credentials removed. Keys of the render that the base lacks are added. Without a base or without merge names the render is the file.
    Returns the file and the sorted names of the copied and dropped keys; never a value.
    """
    # No seed file, or no merge names (a create admitted by a Gateway before them): the render is the file, as before.
    if base.strip() == "" or merge is None:
        return rendered, [], []
    own = set(merge["own"])
    drop = set(merge["drop"]) - own
    base_blocks = env_blocks(base)
    rendered_blocks = [b for b in env_blocks(rendered) if b[0] is not None]
    credentials = {v for k, _, v in base_blocks if k in set(merge["guard"]) and v}

    def credential(value):
        return value in credentials or any(
            len(secret) >= CONTAINED_CREDENTIAL and secret in value for secret in credentials
        )

    owned = {k: raw for k, raw, _ in rendered_blocks if k in own}
    out, kept, copied, dropped = [], set(), set(), set()
    for key, raw, value in base_blocks:
        if key is None:
            # A comment or other line keeps no copy of a credential either, such as a commented-out password.
            if not any(len(secret) >= CONTAINED_CREDENTIAL and secret in raw for secret in credentials):
                out.append(raw)
        elif key in own:
            # The clone's own value takes the default's place; an own key the render lacks (a forgotten key) goes.
            if key in owned and key not in kept:
                out.append(owned[key] if owned[key].endswith("\n") else owned[key] + "\n")
                kept.add(key)
        elif key in drop or credential(value):
            dropped.add(key)
        else:
            out.append(raw)
            kept.add(key)
            copied.add(key)
    if out and not out[-1].endswith("\n"):
        out[-1] += "\n"
    for key, raw, _ in rendered_blocks:
        if key not in kept:
            out.append(raw if raw.endswith("\n") else raw + "\n")
            kept.add(key)
    return "".join(out), sorted(copied), sorted(dropped - copied)


def value_span(raw):
    """The start and end of a block's value inside its raw text: inside the quotes when they close, else the bare value."""
    first = raw.splitlines()[0]
    found = ENV_LINE.fullmatch(first)
    start = found.start(2) + len(found.group(2)) - len(found.group(2).lstrip())
    quote = raw[start : start + 1]
    if quote in {'"', "'"}:
        closing = closing_quote(raw[start + 1 :], quote)
        if closing is not None:
            return start + 1, start + 1 + closing
    return start, start + len(re.split(r"\s+#", first[start:], maxsplit=1)[0].strip())


def rewrite_default_hosts(text, keys, hosts):
    """
    In the values of the named keys, an item that is the default's own host becomes the clone's host. An item is a whole value
    or one part of a list split at commas, semicolons or whitespace, before any `?` or `#`. It is the host alone or with a leading
    `.` or `*.`, a scheme or `//`, a port and a path, query or fragment; the rest of the item stays. A host inside a longer name,
    a path or a query is never an item, and a `${VAR}` reference stays as written. Returns the file and the sorted rewritten names.
    """
    sources = sorted({h.lower() for h in hosts.get("from") or [] if h}, key=len, reverse=True)
    target = hosts.get("to")
    if not target or not sources:
        return text, []
    item = re.compile(
        r"((?:[A-Za-z][A-Za-z0-9+.-]*:)?//|\*?\.)?(" + "|".join(map(re.escape, sources)) + r")((?::[0-9]+)?(?:[/?#].*)?)",
        re.IGNORECASE | re.DOTALL,
    )

    def rewrite(value):
        out, last = [], 0
        for part in re.finditer(r"[^\s,;]+", value):
            if "?" in value[: part.start()] or "#" in value[: part.start()]:
                break
            found = item.fullmatch(part.group(0))
            if found and found.group(2).lower() != target.lower():
                out.append(value[last : part.start()] + (found.group(1) or "") + target + found.group(3))
                last = part.end()
        return "".join(out) + value[last:]

    out, rewritten = [], set()
    for key, raw, _ in env_blocks(text):
        if key is not None and key in keys:
            start, end = value_span(raw)
            changed = raw[:start] + rewrite(raw[start:end]) + raw[end:]
            if changed != raw:
                rewritten.add(key)
                raw = changed
        out.append(raw)
    return "".join(out), sorted(rewritten)


# Run as orbit in the checkout: names one path, relative to the checkout, once in Git's own info/exclude.
EXCLUDE_PROGRAM = r"""
import os, re, subprocess, sys
path = subprocess.run(["git", "rev-parse", "--git-path", "info/exclude"], capture_output=True, text=True, check=True).stdout.strip()
entry = ("/" + re.sub(r"([\\*?\[])", r"\\\1", sys.argv[1])).encode()
data = open(path, "rb").read() if os.path.isfile(path) else b""
if entry in data.splitlines():
    print("unchanged")
    sys.exit(0)
os.makedirs(os.path.dirname(path) or ".", exist_ok=True)
with open(path, "ab") as stream:
    stream.write((b"" if not data or data.endswith(b"\n") else b"\n") + entry + b"\n")
print("changed")
"""


def phase_main(phase, raw):
    c = json.loads(raw)
    changes = []
    ws = c["workspace"]
    root = pathlib.Path
    private = sys.stdin.read()

    def run(argv, check=True, agent=False, orbit=False, timeout=40, private_input=None, isolated=False):
        command = argv
        if agent or orbit:
            argv = ["runuser", "-u", "agent" if agent else "orbit", "--"] + argv
        r = subprocess.run(
            argv,
            check=False,
            input=private_input,
            capture_output=True,
            text=True,
            # A task copy's clean reads file names, which need not be UTF-8: they cross as escaped bytes, never as a crash.
            errors="surrogateescape" if isolated else "strict",
            env=dict(
                os.environ,
                HOME="/home/agent" if agent else "/home/orbit" if orbit else "/root",
                # The task check's own Git environment: no global or system configuration, so no ignore rule of a user's own.
                **({"GIT_CONFIG_GLOBAL": "/dev/null", "GIT_CONFIG_NOSYSTEM": "1", "LC_ALL": "C"} if isolated else {}),
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

    def git(*args, agent=False, check=True, isolated=False, private_input=None):
        return run(
            ["git", "-c", "core.hooksPath=/dev/null", "-c", "core.fsmonitor=false"]
            + list(args),
            agent=agent,
            orbit=not agent,
            check=check,
            isolated=isolated,
            private_input=private_input,
        )

    def require_units():
        for unit in c['units'] + c['processes']:
            if unit.get('desired', 'running') != 'running':
                continue
            if run(['systemctl', 'is-active', '--quiet', unit['name']], False).returncode:
                observed = run(['systemctl', 'show', '--property=ActiveState', '--property=SubState',
                                '--property=Result', '--property=ExecMainStatus', '--property=NRestarts',
                                '--', unit['name']], False)
                state = dict(line.split('=', 1) for line in observed.stdout.splitlines() if '=' in line)
                if state.get('ActiveState') != 'active':
                    raise PhaseRefusal(process_failure(unit, state))

    def checkout():
        """The checkout part of the workspace: the bundle's branch, or a branch at the seed's commit without one, this Instance's mark, and
        the merged `.env`. Names only leave."""
        assert root(ws + "/.git").is_dir()
        mark = root(CHECKOUT_MARK)
        checked_out = json.loads(mark.read_text()) if mark.is_file() else {}
        cleaned = None
        try:
            # This Instance checked out before only when its mark names it; then a retry never moves the branch.
            if checked_out.get("instance_id") != c["instance_id"]:
                before = git("symbolic-ref", "--quiet", "--short", "HEAD").stdout.strip()
                head = git("rev-parse", "HEAD").stdout.strip()
                if before != c["branch"] or head != c["expected_commit"]:
                    if "bundle" in c:
                        git(
                            "fetch",
                            "--quiet",
                            c["bundle"],
                            "+refs/heads/"
                            + c["branch"]
                            + ":refs/remotes/origin/"
                            + c["branch"],
                        )
                    # A branch without a bundle starts at the seed's commit, which the seed's own repository holds.
                    git(
                        "checkout",
                        "--quiet",
                        "-B",
                        c["branch"],
                        "refs/remotes/origin/" + c["branch"] if "bundle" in c else c["expected_commit"],
                    )
                    changes.append("checkout")
                assert git("symbolic-ref", "--short", "HEAD").stdout.strip() == c["branch"]
                assert git("rev-parse", "HEAD").stdout.strip() == c["expected_commit"]
                if "clean_workspace" in c["clone"]:
                    cleaned = clean_workspace()
                checked_out = {"instance_id": c["instance_id"], "commit": c["expected_commit"]}
                mark.parent.mkdir(mode=0o755, parents=True, exist_ok=True)
                # A whole file or none: a crash never leaves a mark that blocks each retry.
                written = mark.with_name(mark.name + ".new")
                written.write_text(json.dumps(checked_out) + "\n")
                written.chmod(0o600)
                os.replace(written, mark)
        finally:
            if "bundle" in c:
                root(c["bundle"]).unlink(missing_ok=True)
        inputs = json.loads(private)
        env = (
            root(c["environment_file"]).read_text()
            if root(c["environment_file"]).exists()
            else ""
        )
        # The seed copy holds the default's own file; the clone keeps it except what must be its own. Names only leave.
        merge = inputs.get("merge")
        updated, copied, dropped = merge_environment(env, inputs["environment"], merge)
        environment_keys = {"copied": copied, "dropped": dropped}
        # A copied value that points at the default's own site points at the clone's. Merge names without hosts come from
        # a Gateway before them: the file stays as that release wrote it.
        if merge is not None and "hosts" in merge:
            updated, environment_keys["rewritten"] = rewrite_default_hosts(updated, set(copied), merge["hosts"])
        # The keys of the connections the clone shares with its default: Orbit renders them as the clone's own. Names only.
        if merge is not None and "shared" in merge:
            written = {key for key, _, _ in env_blocks(updated) if key is not None}
            environment_keys["shared"] = sorted(set(merge["shared"]) & written)
        if env != updated:
            write(c["environment_file"], updated, 1000, 2760, "0640")
        exclude_environment()
        return {"environment": environment_keys, "commit": checked_out["commit"], **({"cleaned": cleaned} if cleaned is not None else {})}

    def clean_workspace():
        """A task copy starts from its start commit alone (development-containers.md#a-task-copy-starts-clean). The seed holds the
        default's tree as it was, so the default's modified tracked files go back to the commit, and its untracked files that Git
        does not ignore go, nested repositories too. Ignored files stay: `vendor`, `node_modules`, built assets, a SQLite file and
        `.env`, which is excluded first, so the merge below still reads the default's file. It runs once, in the first checkout,
        before the mark: T3 is registered and the group can run a turn or a check only after it. Names only leave, at most
        CLEANED_NAMES of each kind, with the counts."""
        exclude_environment()

        def clean_git(*args, private_input=None):
            # The ignore rules the task check reads: no global or system configuration, and the agent's own ignore file, which the
            # check (as `agent`, HOME=/home/agent) reads by default. The C locale keeps `git clean -n`'s words as parsed below.
            # Without global configuration `orbit` loses its trust of the checkout, which another user owns: trust it here, as the check does.
            return git("-c", "safe.directory=" + ws, "-c", "core.quotePath=false", "-c", "core.excludesFile=" + AGENT_IGNORE, *args,
                       isolated=True, private_input=private_input)

        # A skip-worktree (`S`, or `s` with assume-unchanged too) or assume-unchanged (lower case) flag in the default's index hides a
        # tracked file's edit from diff, reset and status. Each flag is cleared in its own call: `update-index` applies only the last
        # flag it is given.
        entries = [entry for entry in clean_git("ls-files", "-v", "-z").stdout.split("\0") if entry]
        for flag, hidden in (("--no-skip-worktree", [e[2:] for e in entries if e[0] in "Ss"]),
                             ("--no-assume-unchanged", [e[2:] for e in entries if e[0].islower()])):
            if hidden:
                clean_git("update-index", flag, "-z", "--stdin", private_input="".join(name + "\0" for name in hidden))
        reset = [name for name in clean_git("diff", "--name-only", "--no-renames", "-z", "HEAD").stdout.split("\0") if name]
        clean_git("reset", "--quiet", "--hard", "HEAD")
        # After the reset, so the clean reads the start commit's `.gitignore`, not the default's edit of it. `git clean -n` names
        # exactly what the clean removes: a folder of ignored files stays, a folder with one is not listed whole. An untracked
        # `.gitignore` hides what it names until it is gone, so the clean runs again until a dry run finds nothing new. A path that a
        # clean could not remove comes back in the next dry run: then the rounds stop and the status check below refuses the tree,
        # as it refuses what CLEAN_ROUNDS rounds leave.
        removed = []
        for _ in range(CLEAN_ROUNDS):
            found = [line.removeprefix("Would remove ") for line in clean_git("clean", "-d", "-ff", "-n").stdout.splitlines()
                     if line.startswith("Would remove ")]
            if not found or set(found) <= set(removed):
                break
            clean_git("clean", "-d", "-ff", "--quiet")
            removed += found
        # A changed submodule or a file that a reset cannot restore fails the copy: a task never starts on a dirty tree. Every
        # untracked file counts, whatever the repository's own `status.showUntrackedFiles` says.
        assert clean_git("status", "--porcelain", "--ignore-submodules=none", "--untracked-files=all").stdout == ""
        if reset or removed:
            changes.append("clean")
        return {"reset_count": len(reset), "reset": sorted(reset)[:CLEANED_NAMES],
                "removed_count": len(removed), "removed": sorted(removed)[:CLEANED_NAMES]}

    def exclude_environment():
        """Git never takes the `.env`, also when the Project's .gitignore does not name it: one line in the checkout's own
        info/exclude, written once, as orbit, who owns the checkout. Its other lines and the Project's files stay as they are
        (development-containers.md)."""
        if not c["environment_file"].startswith(ws + "/"):
            return
        written = run(["python3", "-I", "-c", EXCLUDE_PROGRAM, c["environment_file"][len(ws) + 1:]], orbit=True)
        if written.stdout.strip() == "changed":
            changes.append("exclude")

    def services():
        """The rest of the workspace: PHP settings, file rights, Process files, web config, the Node store and the units."""
        # No Process starts on a branch this Instance did not check out.
        assert json.loads(root(CHECKOUT_MARK).read_text())["instance_id"] == c["instance_id"]
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
        node = run(["python3", "/usr/local/libexec/orbit-instance-node-runtime.py", "clone-fetch", json.dumps(c)], orbit=True, timeout=75, private_input=json.dumps(inputs.get("processes", {})))
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

    # `workspace` runs both parts in order, as one phase; a later workflow can call `checkout` and `services` apart.
    # Each part reads only its half of the one framed JSON value: `.env` and the merge names, or the Process environment files.
    workspace = None
    if phase in {"workspace", "checkout", "services"}:
        if phase != "services":
            workspace = checkout()
        if phase != "checkout":
            services()
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
        # While T3 starts, its own CLI issues the two sessions and adds the project: stock T3's CLI writes its store before and during
        # the server's first start (docs/reference/development-containers.md#create). The tokens leave only as the private reply.
        mark = root(t["project_mark"])
        cli = [(name, [t["t3"], "auth", "session", "issue", "--label", label, "--token-only"]) for name, label in SESSIONS]
        if not mark.exists():
            cli.append(("project", [t["t3"], "project", "add", ws, "--title", t["title"]]))
        with ThreadPoolExecutor(max_workers=len(cli)) as pool:
            started = {name: pool.submit(run, argv, agent=True) for name, argv in cli}
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
            issued = {name: future.result().stdout.strip() for name, future in started.items()}
        environment = json.loads(ready.stdout)
        assert environment["environmentId"] and environment["serverVersion"]
        assert all(issued[name] for name, _ in SESSIONS) and issued["gateway"] != issued["swamp"]
        if "project" in issued:
            write(str(mark), issued["project"] + "\n", 2750, 2750, "0600")
        print(
            json.dumps(
                {
                    "phase": phase,
                    "changes": changes + ["sessions-issued"],
                    "ready_at": __import__("datetime")
                    .datetime.fromtimestamp(
                        answered, __import__("datetime").timezone.utc
                    )
                    .isoformat(),
                    "environment_id": environment["environmentId"],
                    "private_reply": {name: {"token": issued[name]} for name, _ in SESSIONS},
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
        # The T3 step issued both sessions while T3 started; this step only checks them against the running server.
        if not existing:
            raise RuntimeError("instance.t3_sessions_missing")
        for name, _ in SESSIONS:
            token = existing[name]["token"]
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
        # The checkout checked the commit before it wrote the mark; after that, commits on the branch are the user's.
        assert json.loads(root(CHECKOUT_MARK).read_text())["instance_id"] == c["instance_id"]
        if c["php"] is not None:
            run(["php", "-v"], agent=True)
        # No unit check: the Processes start in the completion, whose `check` phase refuses a dead unit.
        reply = {
            "environment": json.dumps(env),
            "gateway": replies[0],
            "swamp": replies[1],
        }
        print(
            json.dumps(
                {
                    "phase": phase,
                    "changes": [],
                    "checked_at": __import__("datetime")
                    .datetime.now(__import__("datetime").timezone.utc)
                    .isoformat(),
                    "private_reply": reply,
                }
            )
        )
        return
    elif phase == "check":
        # After the checkout the branch and its commits are the user's, so the check reads only this Instance's mark.
        assert json.loads(root(CHECKOUT_MARK).read_text())["instance_id"] == c["instance_id"]
        assert git("config", "--global", "user.name", agent=True).stdout.strip()
        if c["php"] is not None:
            run(["php", "-v"], agent=True)
        require_units()
    else:
        raise ValueError(phase)
    print(json.dumps({"phase": phase, "changes": changes, **(workspace or {})}))


if __name__ == "__main__":
    try:
        phase_main(sys.argv[1], sys.argv[2])
    except PhaseRefusal as error:
        print(json.dumps(error.evidence))
        print(error.evidence['error_code'], file=sys.stderr)
        raise SystemExit(1)
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
