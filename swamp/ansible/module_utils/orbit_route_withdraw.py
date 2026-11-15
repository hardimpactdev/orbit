"""One Node's Route withdrawal as one Node-local transaction (docs/reference/routes.md#coordinate-publication).

Under the Node's publication lock it reads the Caddyfile, the shared PHP-FPM pools and the private DNS files, merges the captured
states of the withdrawn Route into what the Node holds, proves that nothing outside the Route changed, keeps the previous
generation of each file it replaces, tests every candidate, then commits and reloads. Two withdrawals of different Routes on one
Node therefore both land: the second reads what the first wrote. The merge is a port of the Swamp composition (sections.ts,
dns.ts); swamp/tests/route_withdraw_parity_test.ts holds the two to the same result.
"""

import fcntl
import json
import os
import pwd
import re
import stat
import tempfile
import time
from pathlib import Path

CADDY_HEADER = "# Managed by Orbit: Node Caddy build\n"
MISSING = object()


class WithdrawFailure(RuntimeError):
    """A named refusal or tool failure; `changed` says whether a live file was replaced."""

    def __init__(self, code, detail="", *, changed=False):
        super().__init__(f"{code}: {detail}" if detail else code)
        self.code = code
        self.changed = changed


# The merge: the same rules, keys and order as the TypeScript composition.

def _json(value):
    """JSON.stringify equality: key order counts, and a missing value differs from null."""
    return None if value is MISSING else json.dumps(value, separators=(",", ":"), ensure_ascii=False)


def _same(a, b):
    return _json(a) == _json(b)


def merge_sections(before, after, current, family, alternatives=()):
    result = dict(current)
    keys = dict.fromkeys([*before, *after, *(key for state in alternatives for key in state)])
    for key in keys:
        previous, desired, actual = before.get(key, MISSING), after.get(key, MISSING), current.get(key, MISSING)
        if _same(previous, desired) and all(_same(state.get(key, MISSING), desired) for state in alternatives):
            continue
        if (not _same(actual, previous) and not _same(actual, desired)
                and not any(_same(actual, state.get(key, MISSING)) for state in alternatives)):
            raise WithdrawFailure(f"{family}.owned_revision_conflict")
        if desired is MISSING:
            result.pop(key, None)
        else:
            result[key] = desired
    return result


def caddy_sections(content):
    if not content.startswith(CADDY_HEADER) or "\0" in content:
        raise WithdrawFailure("caddy.foreign_config")
    matches = list(re.finditer(r"^# orbit: (\S+) ([^\r\n]+)\n", content, re.MULTILINE))
    header = content[:matches[0].start() if matches else len(content)].rstrip("\n") + "\n"
    groups = {}
    for index, match in enumerate(matches):
        end = matches[index + 1].start() if index + 1 < len(matches) else len(content)
        groups.setdefault(f"{match.group(1)}\0{match.group(2)}", []).append(content[match.start():end].rstrip("\n") + "\n")
    return header, groups


def merge_caddy(before, after, current, alternatives=(), before_had_served_groups=False):
    original, desired = caddy_sections(before), caddy_sections(after)
    if not current:
        if before_had_served_groups or original[1]:
            raise WithdrawFailure("caddy.current_config_missing")
        current = original[0]
    observed = caddy_sections(current)
    headers = [original[0], desired[0], *(caddy_sections(state)[0] for state in alternatives)]
    if observed[0] not in headers:
        raise WithdrawFailure("caddy.global_revision_conflict")
    groups = merge_sections(original[1], desired[1], observed[1], "caddy", [caddy_sections(state)[1] for state in alternatives])
    blocks = [block for value in groups.values() for block in value]
    return desired[0] + ("\n" + "\n".join(blocks) if blocks else "")


_POOL = re.compile(r"orbit-(?:instance|workspace|app-instance)-[1-9][0-9]*(?:-[0-9a-f]{8})?")


def php_sections(text):
    prefix, name, groups, directories = "", None, {}, set()
    for line in re.findall(r"[^\n]*\n|[^\n]+\Z", text):
        trimmed = line.strip()
        if trimmed.startswith("["):
            name = trimmed[1:-1]
            if not trimmed.endswith("]") or not _POOL.fullmatch(name) or name in groups:
                raise WithdrawFailure("php.shared_pool_invalid")
            groups[name] = ""
        if name is not None:
            if re.match(r"chdir\s*=", trimmed):
                path = trimmed[trimmed.index("=") + 1:].strip()
                if name in directories or not path.startswith("/") or re.search(r"[\x00-\x1f]", path):
                    raise WithdrawFailure("php.shared_pool_invalid")
                directories.add(name)
            groups[name] += line
        elif not trimmed or trimmed[0] in ";#":
            prefix += line
        else:
            raise WithdrawFailure("php.shared_pool_invalid")
    return prefix, groups


def merge_php(before, after, current):
    observed = php_sections(current)
    groups = merge_sections(php_sections(before)[1], php_sections(after)[1], observed[1], "php")
    if observed[1] and not groups:
        return ""
    return observed[0] + "".join(groups[key] for key in sorted(groups))


_RECORD = re.compile(r"^(host-record)=([^,]+),.+$|^(address|local)=/([^/]+)/")


def dns_records(text):
    result = {}
    for line in text.split("\n"):
        if not line or line.startswith("#"):
            continue
        match = _RECORD.match(line)
        if not match:
            raise WithdrawFailure("dns.foreign_records")
        key = f"{match.group(1) or match.group(3)}:{match.group(2) or match.group(4)}"
        if key in result:
            raise WithdrawFailure("dns.duplicate_record")
        result[key] = line
    return result


def _normalize(value):
    # PHP serializes an empty map as []; these catalog fields are maps.
    return {} if isinstance(value, list) and not value else value


def _requesters(before, after, current):
    as_map = lambda value: value if isinstance(value, dict) else {}
    result, was, will = dict(as_map(current)), as_map(before), as_map(after)
    for key in dict.fromkeys([*was, *will]):
        if _same(was.get(key, MISSING), will.get(key, MISSING)):
            continue
        if key not in will:
            result.pop(key, None)
        else:
            result[key] = will[key]
    return result


def _catalog(before, after, current, alternatives, top=False):
    before, after, current = _normalize(before), _normalize(after), _normalize(current)
    alternatives = [_normalize(value) for value in alternatives]
    values = [before, after, current, *alternatives]
    if all(value is MISSING or isinstance(value, dict) for value in values):
        maps = [value if isinstance(value, dict) else {} for value in values]
        result = {}
        for key in dict.fromkeys(key for value in maps for key in value):
            if top and key == "requesters":
                result[key] = _requesters(maps[0].get(key, MISSING), maps[1].get(key, MISSING), maps[2].get(key, MISSING))
                continue
            value = _catalog(maps[0].get(key, MISSING), maps[1].get(key, MISSING), maps[2].get(key, MISSING),
                             [row.get(key, MISSING) for row in maps[3:]])
            if value is not MISSING:
                result[key] = value
        return result
    as_map = lambda value: {} if value is MISSING else {"value": value}
    return merge_sections(as_map(before), as_map(after), as_map(current), "dns", [as_map(value) for value in alternatives]).get("value", MISSING)


def merge_dns(before, after, current, alternatives=()):
    values = merge_sections(dns_records(before["records"]), dns_records(after["records"]), dns_records(current["records"]), "dns",
                            [dns_records(state["records"]) for state in alternatives])
    catalog = _catalog(json.loads(before["catalog"]), json.loads(after["catalog"]), json.loads(current["catalog"]),
                       [json.loads(state["catalog"]) for state in alternatives], True)
    return {"records": "# Managed by Orbit.\n" + "\n".join(sorted(values.values())) + "\n",
            "catalog": json.dumps(catalog, separators=(",", ":"), ensure_ascii=False) + "\n"}


def compose(plan, live, account=None):
    """The withdrawal applied to what the Node holds: {caddy, pools: {version: text}, dns: {records, catalog}} -> the same shape."""
    out = {"caddy": None, "pools": {}, "dns": None}
    if plan.get("caddy"):
        chain, content = plan["caddy"]["chain"], live["caddy"]
        for step in plan["caddy"]["steps"]:
            content = merge_caddy(chain[step["from"]], chain[step["to"]], content, chain, step["had_served_groups"])
        out["caddy"] = content
    if plan.get("php"):
        resolve = lambda text: text.replace("__ORBIT_NODE_GROUP__", str(account.pw_gid)).replace("/__ORBIT_NODE_HOME__", account.pw_dir)
        for version in plan["php"]["versions"]:
            previous = resolve(_normalize(plan["php"]["before"]).get(version, ""))
            desired = resolve(_normalize(plan["php"]["after"]).get(version, ""))
            if previous != desired:
                out["pools"][version] = merge_php(previous, desired, live["pools"].get(version, ""))
    if plan.get("dns"):
        chain, dns = plan["dns"]["chain"], dict(live["dns"])
        for step in plan["dns"]["steps"]:
            dns = merge_dns(chain[step], chain[step + 1], dns, chain)
        out["dns"] = dns
    return out


# Condition: a withdrawal changes only what its own captured states change. Each check is independent of the merge above.

def _owned(states):
    """The keys a withdrawal may change: its captured states disagree about them, and the last state has none of them. A withdrawal
    removes sections; it never adds or edits one that stays, and no other section is its own."""
    keys = dict.fromkeys(key for state in states for key in state)
    return {key for key in keys if key not in states[-1]
            and any(not _same(state.get(key, MISSING), states[0].get(key, MISSING)) for state in states)}


def _deep(value):
    if isinstance(value, dict):
        return {key: _deep(child) for key, child in value.items()}
    if isinstance(value, list):
        return {} if not value else [_deep(child) for child in value]
    return value


def _untouched_catalog(states, read, result):
    states, read, result = [_deep(s) for s in states], _deep(read), _deep(result)

    def walk(chain, before, after):
        if chain[-1] is not MISSING and not isinstance(chain[-1], dict) or all(_same(value, chain[0]) for value in chain):
            if not _same(before, after):
                raise WithdrawFailure("route.withdraw_foreign_change", "private DNS catalog")
            return
        if all(value is MISSING or isinstance(value, dict) for value in [*chain, before, after]):
            maps = [value if isinstance(value, dict) else {} for value in chain]
            for key in dict.fromkeys([*(k for m in maps for k in m), *(before if isinstance(before, dict) else {}),
                                      *(after if isinstance(after, dict) else {})]):
                walk([m.get(key, MISSING) for m in maps], before.get(key, MISSING) if isinstance(before, dict) else MISSING,
                     after.get(key, MISSING) if isinstance(after, dict) else MISSING)

    walk(states, read, result)


def check_untouched(plan, live, composed, account=None):
    """Refuses with route.withdraw_foreign_change when a site block, PHP scope or DNS record outside the Route would change."""
    if composed["caddy"] is not None:
        chain = [caddy_sections(state) for state in plan["caddy"]["chain"]]
        owned = _owned([groups for _, groups in chain])
        result = caddy_sections(composed["caddy"])
        read = caddy_sections(live["caddy"]) if live["caddy"] else (None, {})
        for key in set(read[1]) | set(result[1]):
            if key not in owned and read[1].get(key) != result[1].get(key):
                raise WithdrawFailure("route.withdraw_foreign_change", "Caddy site " + key.replace("\0", " "))
        if read[0] is not None and read[0] != result[0]:
            raise WithdrawFailure("route.withdraw_foreign_change", "Caddy global options")
    for version, text in composed["pools"].items():
        resolve = lambda t: t.replace("__ORBIT_NODE_GROUP__", str(account.pw_gid)).replace("/__ORBIT_NODE_HOME__", account.pw_dir)
        states = [php_sections(resolve(_normalize(plan["php"][side]).get(version, "")))[1] for side in ("before", "after")]
        owned = _owned(states)
        read, result = php_sections(live["pools"].get(version, "")), php_sections(text)
        for key in set(read[1]) | set(result[1]):
            if key not in owned and read[1].get(key) != result[1].get(key):
                raise WithdrawFailure("route.withdraw_foreign_change", f"PHP {version} scope {key}")
        if text and read[0] != result[0]:
            raise WithdrawFailure("route.withdraw_foreign_change", f"PHP {version} pool header")
    if composed["dns"] is not None:
        chain = plan["dns"]["chain"]
        owned = _owned([dns_records(state["records"]) for state in chain])
        read, result = dns_records(live["dns"]["records"]), dns_records(composed["dns"]["records"])
        for key in set(read) | set(result):
            if key not in owned and read.get(key) != result.get(key):
                raise WithdrawFailure("route.withdraw_foreign_change", "DNS record " + key)
        _untouched_catalog([json.loads(state["catalog"]) for state in chain], json.loads(live["dns"]["catalog"]),
                           json.loads(composed["dns"]["catalog"]))


# The transaction.

class _Lock:
    """An exclusive flock(2) on one of the Node's publication lock files; the deadline is shared by every lock of one withdrawal."""

    def __init__(self, path, deadline, busy):
        self.path, self.deadline, self.busy, self.stream = path, deadline, busy, None

    def __enter__(self):
        try:
            descriptor = os.open(self.path, os.O_RDWR | os.O_CREAT | os.O_NOFOLLOW | os.O_CLOEXEC, 0o600)
        except OSError as error:
            raise WithdrawFailure("route.publish_lock_invalid", str(error)) from error
        self.stream = os.fdopen(descriptor, "rb")
        identity = os.fstat(self.stream.fileno())
        if (not stat.S_ISREG(identity.st_mode) or identity.st_nlink != 1
                or identity.st_uid != os.geteuid() or stat.S_IMODE(identity.st_mode) != 0o600):
            self.stream.close()
            raise WithdrawFailure("route.publish_lock_invalid", "The lock must be a private regular file owned by the publisher.")
        while True:
            try:
                fcntl.flock(self.stream.fileno(), fcntl.LOCK_EX | fcntl.LOCK_NB)
                return self
            except BlockingIOError:
                if time.monotonic() >= self.deadline:
                    self.stream.close()
                    raise WithdrawFailure(self.busy, f"{self.path.name} stayed held past the lock deadline.") from None
                time.sleep(0.1)

    def __exit__(self, *_):
        self.stream.close()


class _File:
    """One live file: what was read, its metadata, and how to put it back."""

    def __init__(self, path, previous, owner=None, mode=0o644):
        # A file that does not exist yet belongs to the publisher (root on a Node).
        owner = owner or (os.geteuid(), os.getegid())
        self.path, self.previous_path = path, previous
        self.old = path.read_bytes() if path.exists() else None
        if self.old is not None:
            info = path.stat()
            owner, mode = (info.st_uid, info.st_gid), stat.S_IMODE(info.st_mode)
        self.owner, self.mode, self.staged = owner, mode, False

    def text(self):
        return "" if self.old is None else self.old.decode()

    def keep_previous(self):
        """The previous generation in the previous folder, root only, with the live file's mode."""
        if self.old is not None:
            _write(self.previous_path, self.old, (os.geteuid(), os.getegid()), self.mode)

    def stage(self, content):
        if content is None:
            self.path.unlink(missing_ok=True)
        else:
            _write(self.path, content.encode(), self.owner, self.mode)
        self.staged = True

    def restore(self):
        if self.staged:
            if self.old is None:
                self.path.unlink(missing_ok=True)
            else:
                _write(self.path, self.old, self.owner, self.mode)
            self.staged = False


def _write(path, data, owner, mode):
    descriptor, name = tempfile.mkstemp(prefix=".orbit-candidate-", dir=path.parent)
    try:
        with os.fdopen(descriptor, "wb") as stream:
            os.fchown(stream.fileno(), *owner)
            os.fchmod(stream.fileno(), mode)
            stream.write(data)
        os.replace(name, path)
    finally:
        Path(name).unlink(missing_ok=True)


def _ok(run, argv, code, files, changed=False):
    rc, _, stderr = run(argv)
    if rc != 0:
        for file in files:
            file.restore()
        raise WithdrawFailure(code, stderr.strip()[-2000:], changed=changed)


def withdraw(plan, run, *, root="/", wait=30, caddy_gid=None):
    """Runs wholly on the Node; run is AnsibleModule.run_command. Returns what changed and where each previous generation is.

    Both locks share one deadline of `wait` seconds, well inside the apply phase's 170-second timeout."""
    base = Path(root)
    at = lambda path: base / path.lstrip("/")
    previous = at("/var/lib/orbit/route-previous")
    locks = []
    if plan.get("caddy") or plan.get("php"):
        locks.append((at("/etc/caddy/.orbit-publish.lock"), "caddy.publish_busy"))
    if plan.get("dns"):
        at("/var/lib/orbit/private-dns").mkdir(parents=True, exist_ok=True)
        locks.append((at("/var/lib/orbit/private-dns/.orbit-publish.lock"), "dns.publish_busy"))
    previous.mkdir(mode=0o700, parents=True, exist_ok=True)
    os.chmod(previous, 0o700)
    deadline = time.monotonic() + wait
    held = []
    try:
        for path, busy in locks:
            held.append(_Lock(path, deadline, busy).__enter__())
        return _transaction(plan, run, at, previous, caddy_gid)
    finally:
        for lock in reversed(held):
            lock.__exit__()


def _transaction(plan, run, at, previous, caddy_gid):
    account = pwd.getpwnam(plan["user"]) if plan.get("php") else None
    caddy = _File(at("/etc/caddy/Caddyfile"), previous / "Caddyfile", (os.geteuid(), caddy_gid or os.getegid()), 0o640) if plan.get("caddy") else None
    pools = {version: _File(at(f"/etc/php/{version}/fpm/pool.d/orbit-scopes.conf"), previous / f"php-{version}-orbit-scopes.conf")
             for version in (plan["php"]["versions"] if plan.get("php") else [])}
    served = {**plan, "dns": None}
    live = {"caddy": caddy.text() if caddy else "", "pools": {v: f.text() for v, f in pools.items() if f.old is not None}, "dns": None}
    composed = compose(served, live, account)
    check_untouched(served, live, composed, account)
    caddy_changed = caddy is not None and composed["caddy"].encode() != caddy.old
    pool_changes = {v: text for v, text in composed["pools"].items() if (text.encode() if text else None) != pools[v].old}

    # Every candidate is tested before anything is reloaded, and the previous generation is kept only once every test passed.
    # The Caddy candidate sits beside the live file, as relative imports need.
    candidate, staged, dns, dns_changed = None, [], [], False
    try:
        if caddy_changed:
            descriptor, name = tempfile.mkstemp(prefix=".orbit-candidate-", dir=caddy.path.parent)
            candidate = Path(name)
            with os.fdopen(descriptor, "wb") as stream:
                os.fchown(stream.fileno(), *caddy.owner)
                os.fchmod(stream.fileno(), caddy.mode)
                stream.write(composed["caddy"].encode())
            _ok(run, ["timeout", "60", "runuser", "-u", "caddy", "--", "caddy", "validate", "--config", str(candidate),
                      "--adapter", "caddyfile"], "caddy.validation_failed", [])
        try:
            kept = _stage_and_test(run, at, previous, plan, caddy, caddy_changed, pools, pool_changes, staged, dns)
        except BaseException:
            # Any failure before the commit, named or not, leaves every written file as it was read.
            for file in staged:
                file.restore()
            raise
        dns_changed = bool(dns) and dns[0] in staged

        # Commit: pools, then Caddy, then DNS, as before. A failed reload puts that file's previous generation back and reloads again.
        pending = list(staged)
        for version in pool_changes:
            rc, _, stderr = run(["timeout", "30", "systemctl", "try-reload-or-restart", f"php{version}-fpm"])
            if rc != 0:
                for file in pending:
                    file.restore()
                for again in pool_changes:
                    run(["timeout", "30", "systemctl", "try-reload-or-restart", f"php{again}-fpm"])
                raise WithdrawFailure("php.reload_failed", stderr.strip()[-2000:])
        pending = [file for file in pending if file not in pools.values()]
        if caddy_changed:
            os.replace(candidate, caddy.path)
            candidate = None
            caddy.staged = True
            rc, _, stderr = run(["timeout", "60", "systemctl", "reload", "caddy"])
            if rc != 0:
                caddy.restore()
                run(["timeout", "60", "systemctl", "reload", "caddy"])
                for file in pending:
                    file.restore()
                raise WithdrawFailure("caddy.reload_failed", stderr.strip()[-2000:], changed=bool(pool_changes))
        if dns_changed:
            rc, _, stderr = run(["timeout", "60", "systemctl", "restart", "dnsmasq"])
            if rc != 0:
                for file in dns:
                    file.restore()
                run(["timeout", "60", "systemctl", "restart", "dnsmasq"])
                raise WithdrawFailure("dns.restart_failed", stderr.strip()[-2000:], changed=caddy_changed or bool(pool_changes))
            _ok(run, ["timeout", "30", "systemctl", "enable", "--now", "orbit-private-dns.socket"], "dns.restart_failed", [], changed=True)
    finally:
        if candidate is not None:
            candidate.unlink(missing_ok=True)
    return {"changed": caddy_changed or bool(pool_changes) or dns_changed, "caddy": caddy_changed,
            "pools": sorted(pool_changes), "dns": dns_changed, "previous": kept}


def _stage_and_test(run, at, previous, plan, caddy, caddy_changed, pools, pool_changes, staged, dns):
    """Writes the pools and the DNS files and tests them, then keeps the previous generations once every test passed. Each file is in
    `staged` before it is written, so the caller puts it back on any failure. Fills `dns` in place; returns the kept copies."""
    for version, text in pool_changes.items():
        staged.append(pools[version])
        pools[version].stage(text or None)
    for version in pool_changes:
        _ok(run, ["timeout", "30", f"/usr/sbin/php-fpm{version}", "-t"], "php.test_failed", staged)
    dns_changed = False
    if plan.get("dns"):
        # Read last, right before the write: some publishers write these files whole without the lock.
        dns[:] = [_File(at("/etc/dnsmasq.d/orbit-records.conf"), previous / "orbit-records.conf"),
                  _File(at("/var/lib/orbit/private-dns/catalog.json"), previous / "catalog.json")]
        if dns[0].old is None or dns[1].old is None:
            raise WithdrawFailure("dns.current_config_missing", "The private DNS records or catalog are missing.")
        only = {"caddy": None, "php": None, "dns": plan["dns"]}
        try:
            live = {"caddy": "", "pools": {}, "dns": {"records": dns[0].text(), "catalog": dns[1].text()}}
            composed = compose(only, live)
            check_untouched(only, live, composed)
        except ValueError as error:
            raise WithdrawFailure("dns.catalog_invalid", str(error)) from error
        dns_changed = composed["dns"]["records"].encode() != dns[0].old or composed["dns"]["catalog"].encode() != dns[1].old
        if dns_changed:
            staged += dns
            dns[0].stage(composed["dns"]["records"])
            dns[1].stage(composed["dns"]["catalog"])
            _ok(run, ["timeout", "30", "dnsmasq", "--test"], "dns.test_failed", staged)
    kept = []
    for file, changed in [(caddy, caddy_changed), *((pools[v], True) for v in pool_changes), *((f, dns_changed) for f in dns)]:
        if file is not None and changed and file.old is not None:
            file.keep_previous()
            kept.append(str(file.previous_path))
    return kept
