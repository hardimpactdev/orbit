"""One Node's Route withdrawal, and a development Route's Caddy publication, each as one Node-local transaction
(docs/reference/routes.md#coordinate-publication).

Under the Node's publication lock it reads the Caddyfile, the shared PHP-FPM pools and the private DNS files, merges the captured
states of the withdrawn Route into what the Node holds, proves that nothing outside the Route changed, keeps the previous
generation of each file it replaces, tests every candidate, then commits and reloads. Two withdrawals of different Routes on one
Node therefore both land: the second reads what the first wrote. The merge is a port of the Swamp composition (sections.ts,
dns.ts); swamp/tests/route_withdraw_parity_test.ts holds the two to the same result.
"""

import grp
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


def merge_php(before, after, current, alternatives=()):
    """`alternatives` are before and after in another form FPM reads the same. They count only for the scopes the two change, so a
    scope that stays keeps its bytes."""
    original, desired, observed = php_sections(before)[1], php_sections(after)[1], php_sections(current)
    changed = {key for key in {*original, *desired} if original.get(key) != desired.get(key)}
    forms = [{**{key: value for key, value in desired.items() if key not in changed},
              **{key: value for key, value in php_sections(state)[1].items() if key in changed}} for state in alternatives]
    groups = merge_sections(original, desired, observed[1], "php", forms)
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


def _as_map(value):
    return value if isinstance(value, dict) else {}


def _requesters(before, after, current):
    result, was, will = dict(_as_map(current)), _as_map(before), _as_map(after)
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
    def leaf(value):
        return {} if value is MISSING else {"value": value}

    return merge_sections(leaf(before), leaf(after), leaf(current), "dns", [leaf(value) for value in alternatives]).get("value", MISSING)


def merge_dns(before, after, current, alternatives=()):
    values = merge_sections(dns_records(before["records"]), dns_records(after["records"]), dns_records(current["records"]), "dns",
                            [dns_records(state["records"]) for state in alternatives])
    catalog = _catalog(json.loads(before["catalog"]), json.loads(after["catalog"]), json.loads(current["catalog"]),
                       [json.loads(state["catalog"]) for state in alternatives], True)
    return {"records": "# Managed by Orbit.\n" + "\n".join(sorted(values.values())) + "\n",
            "catalog": json.dumps(catalog, separators=(",", ":"), ensure_ascii=False) + "\n"}


def _resolve(text, account, group=None):
    """The account placeholders that route-create-site fills in every pool: the primary gid, or `group` in its place."""
    return text.replace("__ORBIT_NODE_GROUP__", group or str(account.pw_gid)).replace("/__ORBIT_NODE_HOME__", account.pw_dir)


def group_name(account):
    """The name of the account's primary group, or None when its gid has none."""
    try:
        return grp.getgrgid(account.pw_gid).gr_name
    except KeyError:
        return None


def compose(plan, live, account=None, group=None):
    """The withdrawal applied to what the Node holds: {caddy, pools: {version: text}, dns: {records, catalog}} -> the same shape.

    `group` is the name of the account's primary group. A publisher before route-create-site wrote it in place of the gid, so a scope of
    the Route in that form counts as the captured one."""
    out = {"caddy": None, "pools": {}, "dns": None}
    if plan.get("caddy"):
        chain, content = plan["caddy"]["chain"], live["caddy"]
        for step in plan["caddy"]["steps"]:
            content = merge_caddy(chain[step["from"]], chain[step["to"]], content, chain, step["had_served_groups"])
        out["caddy"] = content
    if plan.get("php"):
        for version in plan["php"]["versions"]:
            states = [_normalize(plan["php"][side]).get(version, "") for side in ("before", "after")]
            previous, desired = (_resolve(text, account) for text in states)
            if previous != desired:
                named = [_resolve(text, account, group) for text in states] if group else []
                out["pools"][version] = merge_php(previous, desired, live["pools"].get(version, ""), named)
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
        states = [php_sections(_resolve(_normalize(plan["php"][side]).get(version, ""), account))[1] for side in ("before", "after")]
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
    """An exclusive lock on one of the Node's publication lock files, taken by util-linux `flock` on an inherited descriptor: the lock
    belongs to the open file description, so it outlives the `flock` process until this context closes the descriptor."""

    def __init__(self, path, run, seconds, busy, *, shared=False):
        self.shared = shared
        self.path, self.run, self.seconds, self.busy, self.stream = path, run, seconds, busy, None

    def __enter__(self):
        try:
            descriptor = os.open(self.path, os.O_RDWR | os.O_CREAT | os.O_NOFOLLOW | os.O_CLOEXEC, 0o600)
        except OSError as error:
            raise WithdrawFailure("route.publish_lock_invalid", str(error)) from error
        self.stream = os.fdopen(descriptor, "rb")
        identity = os.fstat(self.stream.fileno())
        if (not stat.S_ISREG(identity.st_mode) or identity.st_nlink != 1
                or identity.st_uid != os.geteuid() or identity.st_mode & 0o022):
            self.stream.close()
            raise WithdrawFailure("route.publish_lock_invalid", "The lock must be a regular file of the publisher that no group or other may write.")
        # Another writer may have created it with its default umask: from now on only the publisher may open it.
        os.fchmod(self.stream.fileno(), 0o600)
        rc, _, stderr = self.run(["flock", "--shared" if self.shared else "--exclusive", "--timeout", f"{max(self.seconds, 0.1):.1f}", "--conflict-exit-code", "75",
                                  str(self.stream.fileno())], pass_fds=(self.stream.fileno(),))
        if rc != 0:
            self.stream.close()
            if rc == 75:
                raise WithdrawFailure(self.busy, f"{self.path.name} stayed held past the lock deadline.")
            raise WithdrawFailure("route.publish_lock_failed", stderr.strip()[-2000:])
        return self

    def __exit__(self, *_):
        self.stream.close()


def release_resource_lock(path, run, *, shared=False, seconds=60):
    """Serialize release state with the same inode checks and FD lifetime as the Caddy publisher."""
    return _Lock(path, run, seconds, "deployment.resource_busy", shared=shared)


def caddy_publication_lock(run, seconds=60, *, path=Path("/etc/caddy/.orbit-publish.lock")):
    """Share Caddy's inode-checked publication transaction with native release controllers."""
    return _Lock(path, run, seconds, "caddy.publish_busy")


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
        try:
            return "" if self.old is None else self.old.decode()
        except UnicodeDecodeError:
            raise WithdrawFailure("route.file_not_utf8", f"{self.path} is not UTF-8 text.") from None

    def keep_previous(self):
        """The previous generation in the previous folder, root only, with the live file's mode."""
        if self.old is not None:
            _write(self.previous_path, self.old, (os.geteuid(), os.getegid()), self.mode)

    def still_as_read(self):
        """Refuses when a writer that takes no Node lock changed the file since it was read: it is never overwritten from a stale read."""
        current = self.path.read_bytes() if self.path.exists() else None
        if current != self.old:
            raise WithdrawFailure("route.live_changed", f"{self.path} changed since it was read; run the removal again.")

    def stage(self, content):
        self.still_as_read()
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

    Both locks share one deadline of `wait` seconds, well inside the apply phase's 300-second timeout."""
    base = Path(root)

    def at(path):
        return base / path.lstrip("/")

    previous = at("/var/lib/orbit/route-previous")
    locks = []
    if plan.get("caddy") or plan.get("php"):
        locks.append((at("/etc/caddy/.orbit-publish.lock"), "caddy.publish_busy"))
    if plan.get("dns"):
        at("/var/lib/orbit/private-dns").mkdir(parents=True, exist_ok=True)
        # The lock the Gateway's private DNS publication (DnsmasqPrivateDnsManager) takes on this Node.
        at("/run/lock").mkdir(parents=True, exist_ok=True)
        locks.append((at("/run/lock/orbit-dnsmasq.lock"), "dns.publish_busy"))
    previous.mkdir(mode=0o700, parents=True, exist_ok=True)
    os.chmod(previous, 0o700)
    deadline = time.monotonic() + wait
    held = []
    try:
        for path, busy in locks:
            held.append(_Lock(path, run, deadline - time.monotonic(), busy).__enter__())
        return _transaction(plan, run, at, previous, caddy_gid)
    finally:
        for lock in reversed(held):
            lock.__exit__()


def _transaction(plan, run, at, previous, caddy_gid):
    try:
        account = pwd.getpwnam(plan["user"]) if plan.get("php") else None
    except KeyError:
        raise WithdrawFailure("php.account_missing", f"The account {plan['user']} does not exist on this Node.") from None
    caddy = _File(at("/etc/caddy/Caddyfile"), previous / "Caddyfile", (os.geteuid(), caddy_gid or os.getegid()), 0o640) if plan.get("caddy") else None
    pools = {version: _File(at(f"/etc/php/{version}/fpm/pool.d/orbit-scopes.conf"), previous / f"php-{version}-orbit-scopes.conf")
             for version in (plan["php"]["versions"] if plan.get("php") else [])}
    served = {**plan, "dns": None}
    live = {"caddy": caddy.text() if caddy else "", "pools": {v: f.text() for v, f in pools.items() if f.old is not None}, "dns": None}
    composed = compose(served, live, account, group_name(account) if account else None)
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
            _ok(run, ["timeout", "30", "runuser", "-u", "caddy", "--", "caddy", "validate", "--config", str(candidate),
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
            try:
                caddy.still_as_read()
            except WithdrawFailure:
                for file in pending:
                    file.restore()
                raise
            os.replace(candidate, caddy.path)
            candidate = None
            caddy.staged = True
            rc, _, stderr = run(["timeout", "30", "systemctl", "reload", "caddy"])
            if rc != 0:
                caddy.restore()
                run(["timeout", "30", "systemctl", "reload", "caddy"])
                for file in pending:
                    file.restore()
                raise WithdrawFailure("caddy.reload_failed", stderr.strip()[-2000:], changed=bool(pool_changes))
        if dns_changed:
            rc, _, stderr = run(["timeout", "30", "systemctl", "restart", "dnsmasq"])
            if rc != 0:
                for file in dns:
                    file.restore()
                run(["timeout", "30", "systemctl", "restart", "dnsmasq"])
                raise WithdrawFailure("dns.restart_failed", stderr.strip()[-2000:], changed=caddy_changed or bool(pool_changes))
            _ok(run, ["timeout", "10", "systemctl", "enable", "--now", "orbit-private-dns.socket"], "dns.restart_failed", [], changed=True)
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
        _ok(run, ["timeout", "10", f"/usr/sbin/php-fpm{version}", "-t"], "php.test_failed", staged)
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
            _ok(run, ["timeout", "10", "dnsmasq", "--test"], "dns.test_failed", staged)
    if caddy_changed:
        # Checked once more right before the rename; a change found here leaves every file as it was read.
        caddy.still_as_read()
    kept = []
    for file, changed in [(caddy, caddy_changed), *((pools[v], True) for v in pool_changes), *((f, dns_changed) for f in dns)]:
        if file is not None and changed and file.old is not None:
            file.keep_previous()
            kept.append(str(file.previous_path))
    return kept


# The publication: the other direction of the same transaction (docs/reference/routes.md#coordinate-publication).

# The Caddy source of a development Route's site groups (RouteCaddySiteSource): `<scope>`, `<scope>-bridge`, `<scope>-dev`, `<scope>-dev-bridge`.
PUBLISHED_SOURCE = "app-dev"


def publication_plan(mutations, node_id):
    """One Node's Caddy states of the captured Route publication, or None when the Node serves no change of the Route.

    The create's intent carries the publication as `route_mutations.restore` (the reverse of a publication is its withdrawal, and
    withdraw-route reads the same stage). Its states are sparse: only the Route's own groups."""
    rows = [row for row in (mutations or {}).get("restore", {}).get("publications", [])
            if str(row.get("node_id")) == str(node_id) and row.get("serves_routes") is True]
    if not rows:
        return None
    artifacts = mutations["artifacts"]["caddy"]
    states = [rows[0][name]["caddy"] for name in ("before", "prepared", "withdrawn", "after")]
    if any(index is None for index in states):
        return None
    chain = [artifacts[index] for index in states]
    return {"chain": [state["content"] for state in chain], "had_served_groups": chain[0].get("had_served_groups", False)}


def _changed(states):
    """The keys the captured states disagree about: the published Route's own groups."""
    keys = dict.fromkeys(key for state in states for key in state)
    return [key for key in keys if any(not _same(state.get(key, MISSING), states[0].get(key, MISSING)) for state in states)]


def _blocks(content):
    """The global options and every site block in file order, each with its key; a key can repeat (an Instance with two Routes)."""
    if not content.startswith(CADDY_HEADER) or "\0" in content:
        raise WithdrawFailure("caddy.foreign_config")
    matches = list(re.finditer(r"^# orbit: (\S+) ([^\r\n]+)\n", content, re.MULTILINE))
    header = content[:matches[0].start() if matches else len(content)].rstrip("\n") + "\n"
    blocks = []
    for index, match in enumerate(matches):
        end = matches[index + 1].start() if index + 1 < len(matches) else len(content)
        blocks.append((f"{match.group(1)}\0{match.group(2)}", content[match.start():end].rstrip("\n") + "\n"))
    return header, blocks


def _published_keys(plan):
    """The Route's own groups: the keys its captured states change. Each must be a development site (Caddy source `app-dev`)."""
    owned = _changed([caddy_sections(state)[1] for state in plan["chain"]])
    foreign = [key for key in owned if key.split("\0", 1)[0] != PUBLISHED_SOURCE]
    if foreign:
        raise WithdrawFailure("route.publish_foreign_change", "Caddy site " + foreign[0].replace("\0", " ") + " is not a development site")
    return owned


def compose_publication(plan, current):
    """The Route's own groups put into what the Node holds: each own group becomes its last captured state, where its first block stood
    (added at the end when new, removed when the last state lacks it). Every other block, their order and the global options stay as the
    Node holds them."""
    owned = _published_keys(plan)
    last = caddy_sections(plan["chain"][-1])[1]
    if not current:
        if plan["had_served_groups"]:
            raise WithdrawFailure("caddy.current_config_missing")
        current = caddy_sections(plan["chain"][-1])[0]
    header, blocks = _blocks(current)
    result, placed = [], set()
    for key, block in blocks:
        if key not in owned:
            result.append(block)
        elif key not in placed:
            placed.add(key)
            result += last.get(key, [])
    for key in owned:
        if key not in placed:
            result += last.get(key, [])
    return header + ("\n" + "\n".join(result) if result else ""), owned


def check_published(plan, current, composed):
    """Refuses with route.publish_foreign_change when a block outside the Route's own, their order or the global options would change.
    It works out the own groups from the plan itself, independent of the composition above."""
    owned = set(_published_keys(plan))
    result = _blocks(composed)
    if current:
        read = _blocks(current)
        if read[0] != result[0]:
            raise WithdrawFailure("route.publish_foreign_change", "Caddy global options")
        before = [(key, block) for key, block in read[1] if key not in owned]
    else:
        before = []
    after = [(key, block) for key, block in result[1] if key not in owned]
    if before != after:
        index = next((i for i, (a, b) in enumerate(zip(before, after)) if a != b), min(len(before), len(after)))
        key = (before if index < len(before) else after)[index][0]
        raise WithdrawFailure("route.publish_foreign_change", "Caddy site " + key.replace("\0", " "))


def publish(plan, run, *, root="/", wait=30, caddy_gid=None):
    """One Node's Caddy publication of one Route under the Node lock: read, put in the Route's own groups, compare every other group,
    validate, keep the previous generation, check the live file once more, rename, reload. Caddy reloads even when the file is unchanged,
    as a publication always did: a new certificate of the Route needs it. A refusal leaves the file as it was read."""
    base = Path(root)

    def at(path):
        return base / path.lstrip("/")

    previous = at("/var/lib/orbit/route-previous")
    previous.mkdir(mode=0o700, parents=True, exist_ok=True)
    os.chmod(previous, 0o700)
    with caddy_publication_lock(run, wait, path=at("/etc/caddy/.orbit-publish.lock")):
        caddy = _File(at("/etc/caddy/Caddyfile"), previous / "Caddyfile", (os.geteuid(), caddy_gid or os.getegid()), 0o640)
        current = caddy.text()
        composed, owned = compose_publication(plan, current)
        check_published(plan, current, composed)
        changed = composed.encode() != caddy.old
        kept = []
        if changed:
            descriptor, name = tempfile.mkstemp(prefix=".orbit-candidate-", dir=caddy.path.parent)
            candidate = Path(name)
            try:
                with os.fdopen(descriptor, "wb") as stream:
                    os.fchown(stream.fileno(), *caddy.owner)
                    os.fchmod(stream.fileno(), caddy.mode)
                    stream.write(composed.encode())
                _ok(run, ["timeout", "30", "runuser", "-u", "caddy", "--", "caddy", "validate", "--config", str(candidate),
                          "--adapter", "caddyfile"], "caddy.validation_failed", [])
                caddy.still_as_read()
                if caddy.old is not None:
                    caddy.keep_previous()
                    kept.append(str(caddy.previous_path))
                caddy.still_as_read()
                os.replace(candidate, caddy.path)
                caddy.staged = True
            finally:
                candidate.unlink(missing_ok=True)
        rc, _, stderr = run(["timeout", "30", "systemctl", "reload", "caddy"])
        if rc != 0:
            if changed:
                caddy.restore()
                run(["timeout", "30", "systemctl", "reload", "caddy"])
            raise WithdrawFailure("caddy.reload_failed", stderr.strip()[-2000:])
    return {"changed": changed, "caddy": changed, "previous": kept, "owned": [key.replace("\0", " ") for key in owned]}
