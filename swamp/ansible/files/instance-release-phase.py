#!/usr/bin/python3
"""Execute one Swamp release phase against exactly one AppProd Instance."""
import argparse
import hashlib
import ipaddress
import json
import os
import pwd
import re
import runpy
import secrets
import subprocess
import sys
import tempfile
import time
from contextlib import contextmanager
from pathlib import Path

BASE = Path("/var/lib/orbit/production")
CONFIG = Path("/etc/orbit/production-release.json")
LIB = Path("/usr/local/libexec")
WORK = "/srv/workspace/current"
PROJECT = "orbit-releases"
SHA = re.compile(r"[0-9a-f]{40}")
DOMAIN = re.compile(r"(?=.{1,253}$)(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?")
UNIT = re.compile(r"orbit-(?:process-[1-9][0-9]*-[a-z0-9-]+[.]service|schedule-[0-9a-f]{8}(?:-[0-9a-f]{4}){3}-[0-9a-f]{12}[.](?:service|timer))")
PHASES = ("build", "clone", "park-preparation", "ready", "switch", "public-ready", "switch-back", "after",
          "retire", "release-unused", "readback", "recover")


class Refusal(ValueError):
    pass


def read(path, default=None):
    return json.loads(path.read_text()) if path.exists() else default


def atomic(path, body, mode=0o600):
    fd, name = tempfile.mkstemp(prefix="." + path.name, dir=path.parent)
    try:
        with os.fdopen(fd, "w") as output:
            os.fchmod(output.fileno(), mode)
            output.write(body)
            output.flush()
            os.fsync(output.fileno())
        os.replace(name, path)
        sync_directory(path.parent)
    finally:
        Path(name).unlink(missing_ok=True)


def sync_directory(directory):
    fd = os.open(directory, os.O_RDONLY | os.O_DIRECTORY)
    try:
        os.fsync(fd)
    finally:
        os.close(fd)


def save(path, value):
    atomic(path, json.dumps(value, sort_keys=True, separators=(",", ":")) + "\n")


@contextmanager
def lock(path, *, shared=False):
    relative = path.relative_to(BASE).as_posix()
    if not re.fullmatch(r"[.]capacity/lock|[.]routing[.]lock|legacy-schedules/[1-9][0-9]*/lock|[1-9][0-9]*/[.]release/(?:lock|park[.]lock)", relative):
        raise Refusal("unrecognized production resource lock")
    if shared and not relative.startswith("legacy-schedules/"):
        raise Refusal("shared access requires a production Schedule lock")
    def acquire(arguments, *, pass_fds):
        result = run(arguments, pass_fds=pass_fds, timeout=65, check=False)
        return result.returncode, result.stdout, result.stderr
    publisher = runpy.run_path(str(LIB / "orbit_route_withdraw.py"))
    try:
        with publisher["release_resource_lock"](path, acquire, shared=shared):
            yield
    except publisher["WithdrawFailure"] as error:
        raise Refusal("deployment.resource_busy: cannot acquire the selected production resource") from error


def run(args, *, data=None, timeout=150, check=True, pass_fds=()):
    # Keep program text, credentials, private command output and host environment out of run history.
    if str(args[0]) not in {"incus", "caddy", "curl", "systemctl", "runuser", "tail", "flock",
                            str(LIB / "orbit-instance-release-routes"), str(LIB / "orbit-instance-release-prepare")}:
        raise Refusal("unrecognized production Node executable")
    result = subprocess.run(["/usr/bin/timeout", "--kill-after=5", str(timeout), *args], check=False, input=data, stdin=subprocess.DEVNULL if data is None else None,
                            text=True, capture_output=True, timeout=timeout + 6, pass_fds=pass_fds)
    if check and result.returncode:
        raise Refusal(f"deployment.step_failed: {Path(args[0]).name} exited {result.returncode}")
    return result


def localpath(value):
    if value in ("", "."):
        return ""
    if any(not re.fullmatch(r"[A-Za-z0-9_][A-Za-z0-9_.-]*", part) for part in value.split("/")):
        raise Refusal("deployment.selection_invalid: unsafe application path")
    return value


def positive(value):
    if not re.fullmatch(r"[1-9][0-9]*", str(value)):
        raise Refusal("deployment.selection_invalid: invalid identity")
    return int(value)


@contextmanager
def legacy_schedule_lock(project, *, shared=False):
    directory = BASE / "legacy-schedules" / str(positive(project))
    directory.mkdir(mode=0o755, parents=True, exist_ok=True)
    with lock(directory / "lock", shared=shared):
        yield directory / "adopted"


class Release:
    def __init__(self, instance, sha, operation):
        self.instance = positive(instance)
        self.operation = positive(operation)
        if not SHA.fullmatch(sha):
            raise Refusal("deployment.selection_invalid: invalid commit")
        self.sha = sha
        self.owner = "instance-" + str(self.instance)
        self.prep = "prepare-" + self.owner
        self.name = self.container(sha)
        self.root = BASE / str(self.instance)
        self.private = self.root / ".release"
        self.node = read(CONFIG)
        self.ctl = runpy.run_path(str(LIB / "orbit-release-container"))
        self.state = {}
        self.inventory = {}
        self.operation_file = self.private / "operation.json"

    def container(self, sha):
        if not SHA.fullmatch(sha):
            raise Refusal("deployment.selection_invalid: invalid release identity")
        return self.owner + "-" + sha[:12]

    def persist(self, **updates):
        self.state.update(updates)
        save(self.operation_file, self.state)

    def record(self, sha):
        return self.private / (self.container(sha) + ".json")

    def incus(self, *args, **kwargs):
        return run(["incus", "--force-local", "--project", PROJECT, *args], **kwargs)

    def guest(self, machine, *args, **kwargs):
        return self.incus("exec", machine, "--", *args, **kwargs)

    def user(self, machine, *args, cwd=WORK, **kwargs):
        version = self.state["php_version"]
        path = f"/opt/orbit-runtime/current/bin:/opt/orbit-runtime/current/php/{version}/bin:/usr/local/bin:/usr/bin:/bin"
        return self.incus("exec", machine, "--cwd", cwd, "--", "runuser", "-u", "orbit", "--", "env",
                          "HOME=/home/orbit", "PATH=" + path, *args, **kwargs)

    def guest_action(self, machine, action, plan=None):
        script = (LIB / "orbit-instance-release-guest").read_text()
        arguments = ["/usr/bin/python3", "-I", "-c", script, action]
        options = {"data": json.dumps(plan) if plan is not None else None, "timeout": 1500, "check": False}
        if action == "fetch":
            result = self.guest(machine, "runuser", "-u", "orbit-fetch", "--", *arguments, **options)
        else:
            result = self.user(machine, *arguments, cwd="/srv/workspace", **options)
        if result.returncode == 78 and action == "fetch":
            raise Refusal("deployment.commit_not_on_branch: commit is outside the admitted branch's first-parent history")
        if result.returncode:
            raise Refusal(f"deployment.step_failed: guest {action} failed")
        return result.stdout.strip()

    def primitive(self, action, *args):
        return self.ctl["main"]([action, self.owner, *map(str, args)])

    def identity(self, sha):
        record = self.ctl["release"](self.owner, sha[:12])
        if record["config"][self.ctl["PREFIX"] + "commit"] != sha:
            raise Refusal("deployment.selection_invalid: release identity mismatch")
        return record

    def check_selection(self, expected):
        link = self.root / "live"
        selected = os.readlink(link) if link.is_symlink() else None
        if (link.exists() and not link.is_symlink()) or selected != (self.container(expected) if expected else None):
            raise Refusal("deployment.selection_invalid: the Node live link changed")

    def parse_plan(self, plan):
        if (positive(plan["instance_id"]) != self.instance or positive(plan["operation_id"]) != self.operation
                or positive(plan["node_id"]) != positive(self.node["node_id"]) or plan["sha"] != self.sha):
            raise Refusal("deployment.selection_invalid: admitted identity differs from the Node target")
        if plan["action"] not in ("deploy", "rollback") or not re.fullmatch(r"8[.][0-9]+", plan["php_version"]):
            raise Refusal("deployment.selection_invalid: invalid runtime or action")
        if not plan["routes"] or any(not DOMAIN.fullmatch(r["domain"]) for r in plan["routes"]):
            raise Refusal("deployment.selection_invalid: invalid production Route")
        for route in plan["routes"]:
            positive(route["id"])
            localpath(route["web_root"])
        for path in plan["shared_paths"]:
            localpath(path)
        localpath(plan["application_suffix"].removeprefix("/"))
        if not re.fullmatch(r"/[A-Za-z0-9._~%/=-]*", plan["health_path"]):
            raise Refusal("deployment.selection_invalid: invalid health path")
        if plan["expected_live"] is not None and not SHA.fullmatch(plan["expected_live"]):
            raise Refusal("deployment.selection_invalid: invalid expected live")
        for unit in plan.get("units", []):
            if not UNIT.fullmatch(unit["name"]) or unit["desired"] not in ("running", "stopped"):
                raise Refusal("deployment.selection_invalid: invalid application unit")
        if len(plan["steps"]) > 32:
            raise Refusal("deployment.selection_invalid: too many deployment steps")
        for step in plan["steps"]:
            if not re.fullmatch(r"[a-z0-9][a-z0-9-]{0,62}", step["name"]) or not isinstance(step.get("required", True), bool):
                raise Refusal("deployment.selection_invalid: invalid deployment step identity")
            if step["phase"] not in ("before_activation", "after_activation") or positive(step["timeout_seconds"]) > 86400:
                raise Refusal("deployment.selection_invalid: invalid deployment step")
        legacy = plan.get("legacy")
        if legacy:
            project = positive(legacy["project_id"])
            if legacy["home"] != f"/home/orbit-app-{project}" or legacy.get("commit") not in (None, self.sha):
                raise Refusal("deployment.selection_invalid: first move must select the existing checkout commit")
            fpm = f"orbit-orbit-app-{project}-php{plan['php_version']}-fpm.service"
            if any(not UNIT.fullmatch(unit) and unit != fpm for unit in legacy["units"]):
                raise Refusal("deployment.selection_invalid: invalid legacy unit")

    def reserve(self, plan, *, measured=False):
        # The fleet can deploy separate targets in parallel. Persist each operation's unspent
        # capacity under a Node lock. A crash leaves the reservation for recovery on this target.
        directory = BASE / ".capacity"
        directory.mkdir(mode=0o700, exist_ok=True)
        filename = directory / (self.owner + ".json")
        with lock(directory / "lock"):
            live = self.inventory["live"]
            prep = self.ctl["instance"](self.prep)
            if prep:
                self.ctl["owned"](prep, {"target": self.owner, "role": "prepare"})
            prepared = self.ctl["memory_use"](prep) if prep else (0, 0, 0)
            if live:
                served = self.ctl["memory_use"](self.identity(live))
            elif plan.get("legacy"):
                size = 0
                for name in plan["legacy"]["units"]:
                    current = run(["systemctl", "show", "--value", "-p", "MemoryCurrent", name]).stdout.strip()
                    if current.isdecimal():
                        size += int(current)
                served = (size, size, 0)
            else:
                served = (0, 0, 0)
            if prepared[0] == 0 and not measured and plan.get("memory_limit_bytes"):
                prepared = (positive(plan["memory_limit_bytes"]), 0, 0)
            try:
                result = self.ctl["capacity_estimate"](prepared, served, self.node["pool"])
            except (ValueError, OSError, subprocess.SubprocessError) as error:
                raise Refusal(f"deployment.node_capacity: {error}") from error
            available, needed = result["available_bytes"], result["required_bytes"]
            # Reserve the observed release footprint in addition to the common free-space floor.
            # A fresh cold build obtains its first measured size before cloning.
            current = read(self.record(live), {}) if live else {}
            disk = max(int(current.get("build_bytes", 0)), int(self.state.get("build_bytes", 0)))
            if prep:
                root = (((prep.get("state") or {}).get("disk") or {}).get("root") or {})
                disk = max(disk, int(root.get("usage", 0)))
            if not disk and (plan.get("legacy") or {}).get("commit"):
                legacy = plan["legacy"]
                size = run(["runuser", "-u", "orbit-app-" + str(legacy["project_id"]), "--",
                            "du", "-sxb", "--", legacy["home"] + "/current/"]).stdout
                disk = int(size.split()[0])
            needed["pool"] += disk
            reservations = [read(path) for path in directory.glob("instance-*.json") if path != filename]
            for key in needed:
                left = available[key] - sum(item[key] for item in reservations)
                if left < needed[key]:
                    raise Refusal(f"deployment.node_capacity: {key} needs {needed[key]} bytes, {left} unreserved")
            # These watermarks belong to the Node only once.
            save(filename, {"operation_id": self.operation, "ram": needed["ram"] - 1024**3,
                            "swap": needed["swap"], "pool": disk})

    def free_capacity(self):
        filename = BASE / ".capacity" / (self.owner + ".json")
        if filename.exists():
            with lock(filename.parent / "lock"):
                if (read(filename, {}) or {}).get("operation_id") == self.operation:
                    filename.unlink(missing_ok=True)

    def sql(self, machine, rows, *, verify_only=False):
        try:
            runpy.run_path(str(LIB / "orbit-instance-release-sql"))["reconcile"](
                self.instance, machine, self.state["php_version"], rows, verify_only=verify_only)
        except (ValueError, OSError, KeyError, TypeError, subprocess.SubprocessError) as error:
            raise Refusal("deployment.database_unreachable: could not verify the selected SQL login from the release") from error

    def sql_input(self):
        return self.private / ("sql-" + str(self.operation) + ".json")

    def converge_checkout(self, plan):
        source = {key: plan[key] for key in ("sha", "repository", "branch")}
        source["credential"] = plan.get("credential", "")
        self.guest_action(self.prep, "fetch", source)
        prep = self.ctl["instance"](self.prep)
        packages = self.guest(self.prep, "sha256sum", "/var/lib/dpkg/status").stdout.split()[0]
        context = {key: plan.get(key) for key in ("repository", "branch", "php_version", "environment", "testing_environment",
                                                  "application_suffix", "shared_paths", "needs_vp", "steps")}
        context.update(image=prep["config"]["volatile.base_image"], runtime=prep["expanded_devices"]["runtime"],
                       vite=prep["expanded_devices"].get("vite-plus"), packages=packages)
        scope = hashlib.sha256(json.dumps(context, sort_keys=True, separators=(",", ":")).encode()).hexdigest()
        receipt = self.private / "prepared-checkout.json"
        previous = read(receipt, {})
        # Consume before the first mutation. Crashes or failed builds cannot authorize partial installs.
        save(receipt, {})
        checkout = {key: plan.get(key) for key in ("sha", "environment", "testing_environment", "application_suffix",
                                                   "routes", "shared_paths")}
        inputs = self.guest_action(self.prep, "checkout", {**checkout, "scope": scope, "prepared": previous})
        if not re.fullmatch(r"[0-9a-f]{64}", inputs):
            raise Refusal("deployment.step_failed: invalid dependency input fingerprint")
        return {"scope": scope, "inputs": inputs}

    def build(self, plan):
        self.parse_plan(plan)
        if legacy := plan.get("legacy"):
            with legacy_schedule_lock(legacy["project_id"], shared=True) as marker:
                if marker.exists() or self.legacy_commit(legacy) != legacy["commit"]:
                    raise Refusal("deployment.selection_invalid: physical legacy checkout differs from the admitted commit")
        self.check_selection(plan["expected_live"])
        if self.inventory["live"] != plan["expected_live"]:
            raise Refusal("deployment.selection_invalid: admitted live differs from release inventory")
        bound = {key: value for key, value in plan.items() if key != "credential"}
        fingerprint = hashlib.sha256(json.dumps(bound, sort_keys=True, separators=(",", ":")).encode()).hexdigest()
        if self.state:
            if self.state.get("plan_digest") != fingerprint:
                raise Refusal("deployment.selection_invalid: admitted release input changed on retry")
            if self.state.get("built"):
                return
        else:
            safe = {key: plan[key] for key in ("action", "routes", "health_path", "php_version", "application_suffix")}
            self.persist(**safe, operation=self.operation, sha=self.sha, plan_digest=fingerprint, previous=self.inventory["live"],
                         previous_old=self.inventory["old"], legacy=plan.get("legacy"), shared_paths=plan["shared_paths"], units=[], completed_steps=[], step_outcomes=[])
        sql = plan.get("sql", [])
        if sql:
            save(self.sql_input(), sql)
        self.persist(has_sql=bool(sql))
        if plan["action"] == "rollback":
            if self.inventory["old"] != self.sha:
                raise Refusal("rollback.release_not_retained: selected release is not old")
            metadata = read(self.record(self.sha))
            self.identity(self.sha)
            self.persist(rollback_awake=True, php_version=metadata["php_version"])
            self.primitive("resume", self.sha[:12], self.operation)
            self.sql(self.name, sql)
            self.guest_action(self.name, "environment", plan)
            application = WORK + self.state["application_suffix"]
            if self.user(self.name, "test", "-f", "bootstrap/cache/config.php", cwd=application, check=False).returncode == 0:
                self.user(self.name, "php", "artisan", "config:cache", "--no-interaction", cwd=application)
            units = self.install_units(self.name, plan)
            self.persist(artifact=metadata["artifact"], units=units, built=True)
            return
        if self.sha in (self.inventory["live"], self.inventory["old"]):
            raise Refusal("deployment.selection_invalid: deploy cannot overwrite a retained release")
        self.reserve(plan)
        if not self.state.get("checkout_ready"):
            existed = self.ctl["instance"](self.prep) is not None
            self.persist(preparer_awake=True)
            if existed:
                self.primitive("wake", self.operation)
            run([LIB / "orbit-instance-release-prepare", str(self.instance), plan["php_version"]], timeout=1500)
            if not existed:
                self.primitive("wake", self.operation)
            self.sql(self.prep, sql)
            self.reserve(plan, measured=True)
            self.route_files("prepare")
            dependency_receipt = self.converge_checkout(plan)
            vp = self.prepare_vp(plan)
            self.write_service(plan)
            units = self.install_units(self.prep, plan)
            save(self.private / "after.json", [step for step in plan["steps"] if step["phase"] == "after_activation"])
            self.persist(checkout_ready=True, units=units, vp=vp, dependency_receipt=dependency_receipt)
        self.steps(self.prep, [step for step in plan["steps"] if step["phase"] == "before_activation"], "before")
        artifact = self.guest_action(self.prep, "digest")
        if not re.fullmatch(r"sha256:[0-9a-f]{64}", artifact):
            raise Refusal("deployment.step_failed: invalid completed build digest")
        size = int(self.user(self.prep, "du", "-sxb", "--", WORK + "/").stdout.split()[0])
        self.persist(artifact=artifact, build_bytes=size)
        self.reserve(plan, measured=True)
        if self.state.get("dependency_receipt") and all(row["exit_code"] == 0 for row in self.state["step_outcomes"]):
            save(self.private / "prepared-checkout.json", {**self.state["dependency_receipt"], "artifact": artifact})
        self.persist(built=True)

    def write_service(self, plan):
        config = ["{", "  admin off", "  auto_https off", "  frankenphp", "}"]
        for route in plan["routes"]:
            domain, web = route["domain"], localpath(route["web_root"])
            config.extend([f"http://{domain}:8080 {{", f'  root * "{WORK}/{web}"', "  route {"])
            # Match every path component, including nested project roots. Keep request filtering
            # ahead of PHP rewriting and static dispatch inside an ordered Caddy route.
            config.extend([
                r"    @private path_regexp private (?i)(^|/)([.][^/]*|node_modules|vendor|composer[.](json|lock)|package(-lock)?[.]json|artisan)(/|$)|[.](sqlite[0-9]*(-wal|-shm)?|sql|pem|key|log|bak|phar)(/|$)|~$",
                "    respond @private 404",
            ])
            if not web:
                # A repository root can also contain a real static site. Only its normal web
                # assets and front controller are public. A designated public/ directory can
                # continue to serve application-defined assets, including Laravel public/storage.
                private_roots = sorted({"app", "apps", "bootstrap", "config", "database", "keys", "private", "resources", "routes", "secrets", "src", "storage", "tests", "var"}
                                       | {localpath(path).split("/")[0] for path in plan["shared_paths"]})
                patterns = [pattern for item in private_roots for pattern in ("/" + item, "/" + item + "/*")]
                config.extend(["    @source path " + " ".join(patterns), "    respond @source 404",
                               "    @unpublished {", "      file {path}",
                               r"      not path_regexp public_asset (?i)(/$|^/index[.]php$|^/(robots[.]txt|sitemap[.]xml|manifest[.]json)$|[.](html?|css|m?js|png|jpe?g|svg|gif|webp|avif|ico|woff2?|ttf|otf|eot|mp[34]|webm|webmanifest|wasm)$)",
                               "    }", "    respond @unpublished 404"])
            config.extend(["    php_server {", "      env HTTPS on", "      env SERVER_PORT 443", "    }", "  }", "}"])
        self.guest(self.prep, "tee", "/etc/orbit/release-web.caddy", data="\n".join(config) + "\n")

    def install_units(self, machine, plan):
        units = []
        admitted = {unit["name"] for unit in plan.get("units", [])}
        # The off-traffic preparer may still hold definitions the Instance has since removed.
        previous = self.guest(machine, "systemctl", "list-unit-files", "--no-legend", "--plain").stdout.splitlines()
        for row in previous:
            name = row.split()[0]
            if UNIT.fullmatch(name) and name not in admitted:
                self.guest(machine, "systemctl", "disable", "--now", "--", name)
                self.guest(machine, "rm", "-f", "--", "/etc/systemd/system/" + name)
        for unit in plan.get("units", []):
            name = unit["name"]
            script = unit.get("script")
            if script:
                if (not name.startswith("orbit-schedule-")
                        or not re.fullmatch(r"/etc/orbit/schedules/[a-f0-9-]{36}[.]sh", script["path"])):
                    raise Refusal("deployment.selection_invalid: invalid Schedule script path")
                self.guest(machine, "install", "-d", "-m", "0755", "/etc/orbit/schedules")
                self.guest(machine, "install", "-m", "0400", "-o", "orbit", "/dev/null", script["path"])
                self.guest(machine, "tee", script["path"], data=script["content"])
            env = unit.get("environment_file")
            if env:
                if not re.fullmatch(r"/etc/orbit/processes/[1-9][0-9]*/[0-9a-f]{64}[.]env", env["path"]):
                    raise Refusal("deployment.selection_invalid: invalid Process environment path")
                self.guest(machine, "install", "-d", "-m", "0700", str(Path(env["path"]).parent))
                self.guest(machine, "install", "-m", "0600", "/dev/null", env["path"])
                self.guest(machine, "tee", env["path"], data=env["content"])
            self.guest(machine, "tee", "/etc/systemd/system/" + name, data=unit["content"])
            units.append({"name": name, "desired": unit["desired"]})
        self.guest(machine, "systemctl", "daemon-reload")
        for unit in units:
            self.guest(machine, "systemctl", "disable", "--now", "--", unit["name"])
        return units

    def prepare_vp(self, plan):
        if not plan.get("needs_vp"):
            return []
        helper = "orbit-instance-release-vp.mjs"
        self.incus("file", "push", "--uid", "0", "--gid", "0", "--mode", "0644",
                   LIB / helper, self.prep + "/usr/local/bin/" + helper)
        result = self.user(self.prep, "/opt/orbit-runtime/current/bin/node", "/usr/local/bin/" + helper,
                           WORK + self.state["application_suffix"], timeout=1050, check=False)
        if result.returncode:
            raise Refusal("deployment.vp_store_failed: the checkout's Vite+ prerequisite failed")
        return json.loads(result.stdout)

    def steps(self, machine, steps, phase):
        for index, step in enumerate(steps):
            key = f"{phase}-{index}"
            if key in self.state["completed_steps"]:
                continue
            required = step.get("required", True)
            if self.state.get("executing") == key:
                if required:
                    raise Refusal("deployment.step_failed: an interrupted application step requires reconciliation")
                # The command may have completed. A missing receipt for an optional step leaves
                # its exit status unknown and must never cause the command to run twice.
                code = None
            else:
                self.persist(executing=key)
                limit = positive(step["timeout_seconds"])
                result = self.user(machine, "timeout", "--kill-after=10", str(limit), *self.state.get("vp", []),
                                   "/bin/bash", "--noprofile", "--norc", "-eu", cwd=WORK, data=step["command"], timeout=limit+20, check=False)
                code = result.returncode if 0 <= result.returncode <= 255 else None
            row = {"name": step["name"], "phase": step["phase"], "required": required, "exit_code": code}
            outcomes = [*self.state.get("step_outcomes", []), row]
            if required and code != 0:
                self.persist(step_outcomes=outcomes)
                raise Refusal(f"deployment.step_failed: {phase} step {index + 1} exited {code}")
            self.persist(executing=None, completed_steps=[*self.state["completed_steps"], key], step_outcomes=outcomes)

    def clone(self):
        if self.state["action"] == "rollback":
            return
        if not self.state.get("built"):
            raise Refusal("deployment.selection_invalid: preparation has no completed build")
        self.persist(candidate_attempted=True)
        self.primitive("candidate", self.operation, self.sha, self.state["artifact"])
        # Verify the copy before FrankenPHP or a deploy step can change its private files.
        if not self.state.get("cloned"):
            if self.guest_action(self.name, "digest") != self.state["artifact"]:
                raise Refusal("deployment.selection_invalid: the cloned artifact differs from the build")
            self.persist(cloned=True)

    def park(self):
        marker = self.private / "parked.json"
        with lock(self.private / "park.lock"):
            if self.state.get("preparer_awake") and read(marker) != self.operation:
                prep = self.ctl["instance"](self.prep)
                if prep and prep["config"].get("user.orbit.release.operation") == str(self.operation):
                    self.primitive("sleep", self.operation)
                save(marker, self.operation)

    def probe(self, address=None, *, routes=None, health=None, verify_token=None):
        # Curl owns transient transport/HTTP retries. Count only consecutive 200 responses:
        # a retry interrupts the streak, and all routes share one wall-clock deadline.
        routes = self.state["routes"] if routes is None else routes
        health = self.state["health_path"] if health is None else health
        successes = 0
        deadline = time.monotonic() + 60
        while time.monotonic() < deadline:
            retried = False
            for route in routes:
                domain = route["domain"]
                if address:
                    url = f"http://{address}:8080" + health
                    headers = ["-H", "Host: " + domain, "-H", "X-Forwarded-Proto: https"]
                else:
                    url = "https://" + domain + health
                    headers = ["--header", "@-"] if verify_token else []
                remaining = deadline - time.monotonic()
                if remaining <= 0:
                    raise Refusal("deployment.not_ready: timed out checking production Routes")
                result = run(["curl", "-fsS", "--noproxy", "*", "--max-time", str(min(5, remaining)),
                              "--retry", "60", "--retry-all-errors", "--retry-delay", "1",
                              "-o", "/dev/null", "-w", "%{http_code} %{num_retries}", *headers, "--", url],
                             data="X-Orbit-Release-Verify: " + verify_token + "\n" if verify_token else None,
                             check=False, timeout=remaining)
                code, _, retries = result.stdout.partition(" ")
                if result.returncode or code != "200" or not retries.isdecimal():
                    raise Refusal("deployment.not_ready: the production Route health check failed")
                retried |= int(retries) > 0
            successes = 1 if retried else successes + 1
            if successes >= (3 if address else 1):
                return
        raise Refusal("deployment.not_ready: timed out checking production Routes")

    def ready(self):
        info = self.identity(self.sha)
        if info["config"][self.ctl["PREFIX"] + "artifact"] != self.state["artifact"]:
            raise Refusal("deployment.selection_invalid: different sealed artifact")
        self.sql(self.name, read(self.sql_input()) if self.state.get("has_sql") else [], verify_only=True)
        head = self.user(self.name, "git", "--no-replace-objects", "rev-parse", "HEAD").stdout.strip()
        if head != self.sha:
            raise Refusal("deployment.selection_invalid: different revision in candidate")
        self.guest(self.name, "systemctl", "start", "orbit-frankenphp.service")
        self.guest(self.name, "systemctl", "is-active", "--quiet", "orbit-frankenphp.service")
        self.user(self.name, "php", "-r", "exit(extension_loaded('pcov') || extension_loaded('php_data_bridge') ? 1 : 0);")
        addr = str(ipaddress.IPv4Address(self.primitive("address", self.sha[:12])))
        self.probe(addr)
        self.persist(ready=True, address=addr)

    def units(self, machine, *arguments, **kwargs):
        if machine == "host":
            return run(["systemctl", *arguments], **kwargs)
        return self.guest(machine, "systemctl", *arguments, **kwargs)

    def previous_machine(self):
        return self.container(self.state["previous"]) if self.state["previous"] else "host"

    def capture_work(self):
        previous = self.state["previous"]
        legacy = self.state["legacy"] or {}
        source = [u["name"] for u in read(self.record(previous))["units"]] if previous else legacy.get("units", [])
        machine = self.previous_machine()
        if previous:
            files = self.units(machine, "list-unit-files", "--plain", "--no-legend").stdout
            source = sorted({line.split()[0] for line in files.splitlines() if line and UNIT.fullmatch(line.split()[0])})
        if machine == "host":
            for name in source:
                if not UNIT.fullmatch(name):
                    continue  # The exact project-specific FPM service was validated in parse_plan().
                service = name.removesuffix(".timer") + ".service" if name.endswith(".timer") else name
                user = run(["systemctl", "show", "--value", "-p", "User", service]).stdout.strip()
                directory = run(["systemctl", "show", "--value", "-p", "WorkingDirectory", service]).stdout.strip()
                if user != "orbit-app-" + str(legacy["project_id"]) or not Path(directory).is_relative_to(legacy["home"]):
                    raise Refusal("deployment.selection_invalid: legacy application unit belongs to another target")
        return [{"name": name,
                 "active": self.units(machine, "is-active", "--quiet", "--", name, check=False).returncode == 0,
                 "enabled": self.units(machine, "is-enabled", "--quiet", "--", name, check=False).returncode == 0}
                for name in source]

    def stop_work(self, machine, names):
        # Stop the timers first. A fire already in progress must finish before the other
        # release can enable its timers. Never terminate a running scheduled command.
        timers = [name for name in names if name.endswith(".timer")]
        for name in timers:
            self.units(machine, "disable", "--now", "--", name)
        for timer in timers:
            service = timer.removesuffix(".timer") + ".service"
            deadline = time.monotonic() + 120
            properties = ("show", "-p", "ActiveState,MainPID,ControlPID", service)
            while time.monotonic() < deadline:
                state = dict(line.split("=", 1) for line in self.units(machine, *properties).stdout.splitlines())
                if state["ActiveState"] not in ("active", "activating", "deactivating", "reloading"):
                    break
                # Wait for the observed fire without signaling it. Query its unit again afterwards:
                # an additional ExecStart or ExecStop can acquire the unit before it becomes inactive.
                pid = int(state["MainPID"]) or int(state["ControlPID"])
                if pid <= 0:
                    raise Refusal("deployment.step_failed: running Schedule has no observable process")
                args = ["tail", f"--pid={pid}", "--follow", "/dev/null"]
                remaining = max(0.01, deadline - time.monotonic())
                if machine == "host":
                    run(args, timeout=remaining)
                else:
                    self.guest(machine, *args, timeout=remaining)
            else:
                raise Refusal("deployment.step_failed: a Schedule is still running")
        for name in names:
            if name not in timers and not name.startswith("orbit-schedule-"):
                self.units(machine, "disable", "--now", "--", name)
                if self.units(machine, "is-active", "--quiet", "--", name, check=False).returncode == 0:
                    raise Refusal("deployment.step_failed: previous Process is still active")

    def restore_work(self, machine, entries):
        for unit in entries:
            if unit["enabled"]:
                self.units(machine, "enable", "--", unit["name"])
            if unit["active"]:
                self.units(machine, "start", "--", unit["name"])
                self.units(machine, "is-active", "--quiet", "--", unit["name"])

    def route_files(self, phase):
        payload = {key: self.state.get(key) for key in ("routes", "legacy", "shared_paths", "sha", "health_path", "verify_token", "previous")}
        run([LIB / "orbit-instance-release-routes", phase, str(self.instance), str(self.operation)],
            data=json.dumps(payload), timeout=1200)

    def link(self, sha):
        path = self.root / "live"
        if sha is None:
            path.unlink(missing_ok=True)
        else:
            temp = self.root / ".live.next"
            temp.unlink(missing_ok=True)
            temp.symlink_to(self.container(sha))
            os.replace(temp, path)
        sync_directory(self.root)

    def route_switch(self, *, reverse=False):
        upstream = self.root / "upstream.caddy"
        contents = self.state["saved_upstream"] if reverse else f'reverse_proxy {self.state["address"]}:8080\n'
        selection = self.state["previous"] if reverse else self.sha
        # Multiple Instances share Caddy. File replacement, validation and reload form one
        # Node-wide critical section. On refusal the journal restores the previous selection.
        with lock(BASE / ".routing.lock"):
            try:
                atomic(upstream, contents, 0o644)
                self.route_files("restore" if reverse else "select")
                run(["caddy", "validate", "--config", "/etc/caddy/Caddyfile", "--adapter", "caddyfile"])
                self.link(selection)
                run(["systemctl", "reload", "caddy.service"])
            except (OSError, ValueError, subprocess.SubprocessError):
                if not reverse:
                    atomic(upstream, self.state["saved_upstream"], 0o644)
                    self.route_files("restore")
                    self.link(self.state["previous"])
                    run(["systemctl", "reload", "caddy.service"])
                raise

    @contextmanager
    def migration_lock(self):
        with legacy_schedule_lock(self.state["legacy"]["project_id"]) as marker:
            if marker.is_symlink() or (marker.is_file() and marker.read_text() != f"{self.instance}\n"):
                raise Refusal("deployment.selection_invalid: legacy account is fenced for another Instance")
            yield marker

    def fence(self, enabled):
        with lock(BASE / ".routing.lock"):
            self.route_files("fence" if enabled else "unfence")
            run(["caddy", "validate", "--config", "/etc/caddy/Caddyfile", "--adapter", "caddyfile"])
            run(["systemctl", "reload", "caddy.service"])

    def check_legacy_quiet(self):
        account = "orbit-app-" + str(self.state["legacy"]["project_id"])
        uid = str(pwd.getpwnam(account).pw_uid)
        for status in Path("/proc").glob("[0-9]*/status"):
            try:
                fields = status.read_text(errors="replace").splitlines()
            except (FileNotFoundError, ProcessLookupError):
                continue
            if any(row.startswith("Uid:") and uid in row.split()[1:] for row in fields):
                raise Refusal("deployment.switch_failed: the legacy application account still has a running process")

    def enable_candidate(self):
        for unit in self.state["units"]:
            if unit["desired"] == "running":
                self.units(self.name, "enable", "--now", "--", unit["name"])
                self.units(self.name, "is-active", "--quiet", "--", unit["name"])
        self.guest(self.name, "systemctl", "enable", "--now", "orbit-frankenphp.service")
        self.incus("config", "set", self.name, "boot.autostart=true")

    def move_legacy(self):
        with self.migration_lock() as marker:
            if not self.state.get("migration_committed") and self.legacy_commit() != self.state["legacy"]["commit"]:
                raise Refusal("deployment.selection_invalid: physical legacy checkout changed before transfer")
            # The old schedule dispatcher takes this same lock shared. The marker also blocks
            # a queued fire after this process dies or the Node reboots mid-migration.
            atomic(marker, f"{self.instance}\n")
            if not self.state.get("migration_committed"):
                self.fence(True)
                self.stop_work("host", [unit["name"] for unit in self.state["previous_work"]])
                self.check_legacy_quiet()
                self.route_switch()
                self.check_selection(self.sha)
                self.probe(self.state["address"])
                self.probe(verify_token=self.state["verify_token"])
                self.check_legacy_quiet()
                if self.legacy_commit() != self.state["legacy"]["commit"]:
                    raise Refusal("deployment.selection_invalid: physical legacy checkout changed during transfer")
                self.persist(migration_committed=True)
            # A candidate job, or the first public request after unfencing, can write to the
            # new backing. Never copy the old backing again after this durable commit.
            self.check_selection(self.sha)
            self.identity(self.sha)
            self.primitive("resume", self.sha[:12], self.operation)
            self.enable_candidate()
            self.fence(False)
            self.persist(switched=True)

    def legacy_commit(self, legacy=None):
        legacy = self.state["legacy"] if legacy is None else legacy
        return run(["runuser", "-u", "orbit-app-" + str(legacy["project_id"]), "--", "git",
                    "--no-replace-objects", "-C", legacy["home"] + "/current", "rev-parse", "HEAD"]).stdout.strip()

    def legacy_witness(self):
        legacy = self.state["legacy"]
        if not self.state.get("cleaned") or self.state.get("migration_committed") or not legacy:
            return {}
        if self.state.get("switch_started") and not self.state.get("reversed"):
            return {}
        with self.migration_lock() as marker:
            if marker.exists() or (self.root / "selected/upstream.caddy").is_symlink() or (self.root / "selected/00-release-fence.caddy").exists():
                return {}
            revision = self.legacy_commit()
            if revision != legacy["commit"]:
                return {}
            for unit in self.state.get("previous_work", []):
                active = self.units("host", "is-active", "--quiet", "--", unit["name"], check=False).returncode == 0
                enabled = self.units("host", "is-enabled", "--quiet", "--", unit["name"], check=False).returncode == 0
                if unit["active"] != active or unit["enabled"] != enabled:
                    return {}
            self.probe()
            return {"legacy_restored": True, "legacy_commit": revision}

    def verify_restored(self):
        previous = self.state["previous"]
        inventory = read(self.private / "inventory.json", {"live": None, "old": None})
        if inventory != {"live": previous, "old": self.state["previous_old"]}:
            raise Refusal("deployment.selection_invalid: different inventory after switch-back")
        self.check_selection(previous)
        if (self.root / "upstream.caddy").read_text() != self.state["saved_upstream"]:
            raise Refusal("deployment.selection_invalid: different upstream after switch-back")
        if previous:
            former = read(self.record(previous))
            self.readback()
            self.probe(routes=former["routes"], health=former.get("health_path", self.state["health_path"]))
        elif legacy := self.state["legacy"]:
            revision = self.legacy_commit()
            if revision != legacy["commit"]:
                raise Refusal("deployment.selection_invalid: different legacy commit after switch-back")
            self.probe(verify_token=self.state["verify_token"])
        else:
            # A new Instance has no former release. Check the public response from its existing
            # preview Route against the baseline captured before applying the switch.
            if self.public_statuses() != self.state["initial_statuses"]:
                raise Refusal("deployment.switch_failed: the original preview response was not restored")

    def public_statuses(self):
        statuses = []
        for route in self.state["routes"]:
            result = run(["curl", "-sS", "--noproxy", "*", "--max-time", "5", "-o", "/dev/null", "-w", "%{http_code}",
                          "--", "https://" + route["domain"] + self.state["health_path"]])
            if not re.fullmatch(r"[1-5][0-9][0-9]", result.stdout):
                raise Refusal("deployment.not_ready: cannot observe the initial preview Route")
            statuses.append(result.stdout)
        return statuses

    def switch(self):
        if self.state.get("switched"):
            self.check_selection(self.sha)
            return
        if not self.state.get("ready"):
            raise Refusal("deployment.not_ready: candidate has not passed readiness")
        if not self.state.get("switch_started"):
            self.check_selection(self.state["previous"])
            if previous := self.state["previous"]:
                self.identity(previous)
                self.primitive("resume", previous[:12], self.operation)
            if legacy := self.state["legacy"]:
                with self.migration_lock():
                    if self.legacy_commit() != legacy["commit"]:
                        raise Refusal("deployment.selection_invalid: physical legacy checkout changed before switching")
            initial = self.public_statuses() if not self.state["previous"] and not self.state["legacy"] else []
            self.persist(initial_statuses=initial, verify_token=secrets.token_hex(32) if self.state["legacy"] else None,
                         saved_upstream=(self.root / "upstream.caddy").read_text(), previous_work=self.capture_work(),
                         switch_started=True)
        if self.state.get("reversed") or self.state.get("reversing"):
            self.reverse()
            raise Refusal("deployment.switch_failed: this operation is restoring its predecessor")
        try:
            if self.state["legacy"]:
                self.move_legacy()
                return
            self.route_switch()
            # Only one release may consume work even if the Node reboots mid-switch.
            previous = self.state["previous"]
            if previous:
                self.incus("config", "set", self.container(previous), "boot.autostart=false")
            if previous:
                self.stop_work(self.container(previous), [u["name"] for u in self.state["previous_work"]])
            self.enable_candidate()
            self.persist(switched=True)
        except (Refusal, OSError, subprocess.SubprocessError) as error:
            if self.state.get("migration_committed"):
                raise Refusal("deployment.switch_failed: migration committed, recover the new release forward") from error
            self.reverse()
            raise Refusal("deployment.switch_failed: restored the previous selection") from error

    def reverse(self):
        if self.state.get("migration_committed"):
            self.move_legacy()
            self.public_ready()
            return
        if not self.state.get("switch_started") or self.state.get("reversed") or self.state.get("verified"):
            return
        self.persist(reversing=True)
        self.identity(self.sha)
        self.stop_work(self.name, [u["name"] for u in self.state["units"]])
        self.guest(self.name, "systemctl", "disable", "orbit-frankenphp.service")
        self.incus("config", "set", self.name, "boot.autostart=false")
        if previous := self.state["previous"]:
            self.identity(previous)
            self.primitive("resume", previous[:12], self.operation)
            self.guest(self.container(previous), "systemctl", "enable", "--now", "orbit-frankenphp.service")
            self.incus("config", "set", self.container(previous), "boot.autostart=true")
        if self.state["legacy"]:
            with self.migration_lock() as marker:
                self.restore_work("host", self.state["previous_work"])
                self.route_switch(reverse=True)
                self.verify_restored()
                self.fence(False)
                self.probe()
                marker.unlink(missing_ok=True)
                sync_directory(marker.parent)
        else:
            self.restore_work(self.previous_machine(), self.state["previous_work"])
            self.route_switch(reverse=True)
            self.verify_restored()
        self.persist(switched=False, reversed=True)

    def public_ready(self):
        if not self.state.get("switched"):
            raise Refusal("deployment.switch_failed: the selected candidate has not switched")
        self.check_selection(self.sha)
        self.probe()
        self.units(self.name, "is-active", "--quiet", "orbit-frankenphp.service")
        for unit in self.state["units"]:
            if unit["desired"] == "running":
                self.units(self.name, "is-active", "--quiet", "--", unit["name"])
        save(self.record(self.sha), {"artifact": self.state["artifact"], "units": self.state["units"],
                                     "routes": self.state["routes"], "health_path": self.state["health_path"], "php_version": self.state["php_version"],
                                     "build_bytes": self.state.get("build_bytes", read(self.record(self.sha), {}).get("build_bytes", 0))})
        save(self.private / "inventory.json", {"live": self.sha, "old": self.state["previous"]})
        self.persist(verified=True)

    def after(self):
        if not self.state.get("verified"):
            raise Refusal("deployment.not_ready: public release has not passed readiness")
        if self.state["action"] == "deploy":
            self.steps(self.name, read(self.private / "after.json", []), "after")

    def discard(self, sha, *, owned_build=False):
        name = self.container(sha)
        item = self.ctl["instance"](name)
        if item:
            item = self.identity(sha)
            if owned_build and item["config"][self.ctl["PREFIX"] + "operation"] != str(self.operation):
                raise Refusal("deployment.selection_invalid: candidate belongs to another Operation")
            if item["config"].get("boot.autostart") != "false":
                raise Refusal("deployment.selection_invalid: refusing to remove an autostart release")
            self.incus("delete", name, "--force")
        self.primitive("forget", sha[:12])
        self.record(sha).unlink(missing_ok=True)

    def retire(self):
        if not self.state.get("verified"):
            raise Refusal("deployment.not_ready: refusing to retire before public verification")
        self.check_selection(self.sha)
        previous, old = self.state["previous"], self.state["previous_old"]
        if previous and not self.state.get("parked_previous"):
            self.identity(previous)
            # A graceful web stop drains the old connections; a failed stop keeps this release recoverable.
            self.guest(self.container(previous), "systemctl", "disable", "--now", "orbit-frankenphp.service")
            self.primitive("retire", previous[:12], self.operation)
            self.persist(parked_previous=True)
        if old and old not in (self.sha, previous):
            self.discard(old)
        self.route_files("finish")
        self.free_capacity()
        self.sql_input().unlink(missing_ok=True)
        self.persist(retired=True)
        (self.private / "after.json").unlink(missing_ok=True)

    def release_unused(self):
        if self.state.get("migration_committed") and not self.state.get("verified"):
            self.move_legacy()
            self.public_ready()
        if self.state.get("verified"):
            if not self.state.get("retired"):
                self.retire()
            return
        self.reverse()
        self.check_selection(self.state["previous"])
        if self.state["action"] == "rollback":
            if self.state.get("rollback_awake") and self.sha == self.inventory["old"]:
                self.identity(self.sha)
                self.guest(self.name, "systemctl", "stop", "orbit-frankenphp.service")
                self.primitive("retire", self.sha[:12], self.operation)
        elif self.state.get("candidate_attempted") and self.sha not in (self.inventory["live"], self.inventory["old"]):
            self.discard(self.sha, owned_build=True)
            snapshot = "built-" + str(self.operation)
            snapshots = json.loads(self.incus("snapshot", "list", self.prep, "--format", "json").stdout)
            for seal in snapshots:
                if seal["name"] == snapshot:
                    self.ctl["owned"](seal, {"target": self.owner, "role": "prepare", "operation": str(self.operation),
                                            "commit": self.sha, "artifact": self.state["artifact"]})
                    self.incus("snapshot", "delete", self.prep, snapshot)
        self.park()
        self.free_capacity()
        (self.private / "after.json").unlink(missing_ok=True)
        self.sql_input().unlink(missing_ok=True)
        self.persist(cleaned=True)

    def readback(self):
        inventory = read(self.private / "inventory.json", {"live": None, "old": None})
        live = inventory["live"]
        self.check_selection(live)
        result = {"instance_id": self.instance, "operation_id": self.operation, "node_id": positive(self.node["node_id"]),
                  "live": live, "old": inventory["old"], "name": None, "address": None, "release": None, "artifact": None}
        if self.state.get("operation") == self.operation and self.state["sha"] == self.sha:
            if live is None and self.state.get("legacy"):
                result.update(self.legacy_witness())
            outcomes = self.state.get("step_outcomes", [])
            result["step_outcomes"] = outcomes
            result["best_effort_steps"] = [{key: step[key] for key in ("name", "phase", "exit_code")}
                                           for step in outcomes if not step["required"]]
        if live:
            record = self.identity(live)
            name = self.container(live)
            address = self.primitive("address", live[:12])
            if (self.root / "upstream.caddy").read_text().strip() != f"reverse_proxy {address}:8080":
                raise Refusal("deployment.selection_invalid: upstream differs from the selected container")
            result.update(name=name, address=address, release=live[:12], artifact=record["config"][self.ctl["PREFIX"] + "artifact"])
        return result

    def execute(self, phase):
        self.root.mkdir(mode=0o755, parents=True, exist_ok=True)
        self.private.mkdir(mode=0o700, exist_ok=True)
        if phase == "park-preparation":
            # Candidate readiness can proceed while memory is reclaimed. Only this operation's
            # marker is written by park(), and the native DAG joins both branches before switch.
            self.state = read(self.operation_file, {})
            if self.state.get("operation") != self.operation or self.state.get("sha") != self.sha:
                raise Refusal("deployment.selection_invalid: another release owns preparation")
            self.park()
            return
        with lock(self.private / "lock"):
            self.inventory = read(self.private / "inventory.json", {"live": None, "old": None})
            self.state = read(self.operation_file, {})
            if (self.state.get("switched") and not self.state.get("verified")
                    and self.inventory["live"] == self.state["sha"]):
                self.persist(verified=True)
            if phase == "readback":
                return self.readback()
            if self.state and self.state["operation"] != self.operation:
                if self.state.get("retired") or self.state.get("cleaned"):
                    if phase in ("release-unused", "switch-back"):
                        return  # The new build refused before it acquired this settled target.
                    if phase != "build":
                        raise Refusal("deployment.selection_invalid: operation has no admitted build")
                    self.state = {}
                else:
                    raise Refusal("deployment.selection_invalid: another release owns the Instance")
            if self.state and self.state["sha"] != self.sha:
                raise Refusal("deployment.selection_invalid: operation commit changed")
            if phase == "recover" and self.state:
                self.release_unused()
                return self.readback()
            if phase == "build":
                self.build(json.load(sys.stdin))
            elif not self.state:
                if phase not in ("release-unused", "switch-back"):
                    raise Refusal("deployment.selection_invalid: release has no admitted build")
            else:
                method = {"park-preparation": self.park, "public-ready": self.public_ready, "switch-back": self.reverse,
                          "release-unused": self.release_unused}.get(phase)
                (method if method is not None else getattr(self, phase))()


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("phase", choices=PHASES)
    parser.add_argument("instance")
    parser.add_argument("sha")
    parser.add_argument("operation")
    args = parser.parse_args()
    result = Release(args.instance, args.sha, args.operation).execute(args.phase)
    if result is not None:
        print(json.dumps(result))


if __name__ == "__main__":
    try:
        main()
    except Refusal as error:
        sys.exit(str(error))
    except (KeyError, OSError, ValueError, subprocess.SubprocessError) as error:
        # The Incus and build subprocess arguments can reference private runtime inputs.
        code = "deployment.node_capacity" if "needs " in str(error) else "deployment.selection_invalid"
        sys.exit(f"{code}: unable to complete the admitted release phase ({type(error).__name__})")
