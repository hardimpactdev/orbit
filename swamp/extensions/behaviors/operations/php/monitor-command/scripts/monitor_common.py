"""One-action entry helpers for journaled monitoring pool publication."""

import json
import os
import shutil
import uuid

from runtime_common import (
    METRICS_BACKUP,
    METRICS_PENDING,
    PENDING,
    Host,
    P,
    Refused,
    Runtime,
    check,
    confirm,
    digest,
    directory,
    exists,
    file_hash,
    generation_applied,
    inspect,
    pool_generation,
    publish,
    regular,
    reload,
    runtime_lock,
    select_pool,
    served,
    sync,
    tuning_allowed,
    unlink,
    validate,
)


def context():
    raw = json.loads(os.environ["ORBIT_PHP_MONITOR_INPUT"])
    check(
        isinstance(raw, dict) and set(raw) == {"run_id", "runtime"}, "public envelope"
    )
    check(str(uuid.UUID(raw["run_id"])) == raw["run_id"], "native run identity")
    data = raw["runtime"]
    check(data["mode"] == "monitor", "monitoring mode only")
    host = Host()
    runtime = Runtime(host, data)
    return host, runtime, data, runtime.directory / (".metrics-native-" + raw["run_id"])


def trusted(host, path, mode):
    check(regular(path) and host.root(path, mode), "trusted monitoring file")


def state(host, work):
    check(directory(work) and host.root(work, 0o700), "trusted monitoring stage")
    path = work / "state.json"
    trusted(host, path, 0o600)
    return json.loads(path.read_text())


def save(work, value):
    publish(work, work / "state.json", json.dumps(value), 0o600)
    sync(work)


def view(snapshot):
    return {key: snapshot[key] for key in ("present", "changed", "active")}


def base_pool(text, user):
    output = []
    default = False
    for line in text.splitlines(keepends=True):
        if line.startswith("["):
            default = line.strip() == "[orbit-" + user + "]"
        if default and line.startswith(("pm.status_path = ", "pm.status_listen = ")):
            continue
        output.append(line)
    return "".join(output)


def unchanged(host, runtime, snapshot, pool=True):
    check(runtime.matches(host), "Instance identity remains current")
    check(not exists(runtime.receipt(PENDING)), "no deployment generation pending")
    for name, expected in snapshot["hashes"].items():
        if name == "pool.conf" and not pool:
            continue
        path = (
            runtime.local
            if name == "local"
            else runtime.unit
            if name == "unit"
            else runtime.generated / name
        )
        trusted(host, path, 0o644)
        check(file_hash(path) == expected, "runtime changed after staging")


def journal_owned(host, runtime, snapshot):
    pending, backup = runtime.receipt(METRICS_PENDING), runtime.receipt(METRICS_BACKUP)
    trusted(host, pending, 0o600)
    trusted(host, backup, 0o600)
    expected = (
        ("active" if snapshot["active"] else "stopped")
        + " "
        + snapshot["desired_hash"]
        + "\n"
    )
    check(
        pending.read_text() == expected and file_hash(backup) == snapshot["prior_hash"],
        "monitoring journal belongs to this publication",
    )


def stage(host, runtime, data, work):
    if not regular(runtime.marker) or not directory(runtime.generated):
        return {"present": False, "changed": False, "active": False}
    runtime = Runtime(host, data, True)
    with runtime_lock(host, runtime.user):
        check(runtime.matches(host), "Instance identity matches")
        check(
            directory(runtime.directory) and host.root(runtime.directory, 0o755),
            "trusted runtime directory",
        )
        check(
            directory(runtime.generated) and host.root(runtime.generated, 0o755),
            "trusted generated directory",
        )
        if exists(work):
            snapshot = state(host, work)
            check(
                snapshot["input_hash"]
                == digest(json.dumps(data, sort_keys=True).encode()),
                "stage matches public intent",
            )
            return view(snapshot)
        check(
            not exists(runtime.receipt(METRICS_PENDING))
            and not exists(runtime.receipt(METRICS_BACKUP)),
            "owning monitoring recovery required",
        )
        check(not exists(runtime.receipt(PENDING)), "no deployment generation pending")
        hashes = {}
        for name, path in [
            (name, runtime.generated / name)
            for name in ("php-fpm.conf", "pool.conf", "master.ini", "local.sha256")
        ] + [("local", runtime.local), ("unit", runtime.unit)]:
            trusted(host, path, 0o644)
            hashes[name] = file_hash(path)
        configuration = data["configuration"]
        check(
            (runtime.generated / "php-fpm.conf").read_text() == configuration["main"],
            "main configuration current",
        )
        check(
            (runtime.generated / "master.ini").read_text()
            == configuration["master_ini"],
            "master settings current",
        )
        check(runtime.unit.read_text() == configuration["unit"], "runtime unit current")
        desired = select_pool(data)
        prior = (runtime.generated / "pool.conf").read_bytes()
        check(
            base_pool(prior.decode(), runtime.user)
            == base_pool(desired.decode(), runtime.user),
            "monitoring preserves current applications",
        )
        check(
            pool_generation(prior) == pool_generation(desired),
            "monitoring preserves release generation",
        )
        for path in served(desired.decode(), "chdir"):
            resolved = P(path).resolve(strict=True)
            check(
                resolved.is_relative_to(runtime.home / "releases")
                and resolved.is_dir(),
                "served application remains in releases",
            )
        local = runtime.local.read_bytes()
        check(
            tuning_allowed(
                local.decode(),
                "orbit-" + runtime.user,
                "pm.status_path = /orbit-fpm-status" in desired.decode().splitlines(),
            ),
            "operator tuning remains valid",
        )
        active = host.active(runtime.service)
        if active:
            check(
                generation_applied(host, runtime, prior),
                "running generation belongs to pool",
            )
        work.mkdir(mode=0o700)
        for name, content in {
            "pool.conf": desired,
            "php-fpm.conf": configuration["main"].encode(),
            "master.ini": configuration["master_ini"].encode(),
            "local.conf": local,
        }.items():
            publish(work, work / name, content, 0o600)
        snapshot = {
            "present": True,
            "changed": prior != desired,
            "active": active,
            "hashes": hashes,
            "prior_hash": digest(prior),
            "desired_hash": digest(desired),
            "phase": "prepared",
            "input_hash": digest(json.dumps(data, sort_keys=True).encode()),
        }
        save(work, snapshot)
        return view(snapshot)


def validate_pool(host, runtime, _data, work):
    snapshot = state(host, work)
    trusted(host, work / "pool.conf", 0o600)
    check(
        file_hash(work / "pool.conf") == snapshot["desired_hash"], "candidate unchanged"
    )
    validate(host, runtime, work, (work / "php-fpm.conf").read_text())
    return {"validated": True}


def publish_pool(host, runtime, data, work):
    with runtime_lock(host, runtime.user):
        snapshot = state(host, work)
        if not snapshot["changed"]:
            return {"changed": False}
        if snapshot["phase"] == "published":
            journal_owned(host, runtime, snapshot)
            check(
                file_hash(runtime.generated / "pool.conf") == snapshot["desired_hash"],
                "published pool remains current",
            )
            return {"changed": True}
        unchanged(host, runtime, snapshot)
        check(
            digest(select_pool(data)) == snapshot["desired_hash"],
            "application release remains current",
        )
        check(
            host.active(runtime.service) == snapshot["active"],
            "service state remains current",
        )
        check(
            not exists(runtime.receipt(METRICS_PENDING))
            and not exists(runtime.receipt(METRICS_BACKUP)),
            "no foreign monitoring journal",
        )
        desired = (work / "pool.conf").read_bytes()
        check(digest(desired) == snapshot["desired_hash"], "validated pool unchanged")
        snapshot["phase"] = "publishing"
        save(work, snapshot)
        publish(
            runtime.directory,
            runtime.receipt(METRICS_BACKUP),
            (runtime.generated / "pool.conf").read_bytes(),
            0o600,
        )
        pending = (
            ("active" if snapshot["active"] else "stopped")
            + " "
            + snapshot["desired_hash"]
            + "\n"
        )
        publish(runtime.directory, runtime.receipt(METRICS_PENDING), pending, 0o600)
        sync(runtime.directory)
        snapshot["phase"] = "published"
        save(work, snapshot)
        publish(runtime.generated, runtime.generated / "pool.conf", desired, 0o644)
        sync(runtime.generated)
    return {"changed": True}


def reload_pool(host, runtime, _data, work):
    with runtime_lock(host, runtime.user):
        snapshot = state(host, work)
        unchanged(host, runtime, snapshot, pool=False)
        if snapshot["changed"] and snapshot["active"]:
            journal_owned(host, runtime, snapshot)
            check(
                file_hash(runtime.generated / "pool.conf") == snapshot["desired_hash"],
                "published pool remains current",
            )
            check(host.active(runtime.service), "service remains active")
            reload(host, runtime)
    return {"reloaded": snapshot["changed"] and snapshot["active"]}


def clear_journal(host, runtime, snapshot):
    pending, backup = runtime.receipt(METRICS_PENDING), runtime.receipt(METRICS_BACKUP)
    if exists(pending):
        trusted(host, pending, 0o600)
        expected = (
            ("active" if snapshot["active"] else "stopped")
            + " "
            + snapshot["desired_hash"]
            + "\n"
        )
        check(
            pending.read_text() == expected, "remaining journal belongs to publication"
        )
    if exists(backup):
        trusted(host, backup, 0o600)
        check(
            file_hash(backup) == snapshot["prior_hash"],
            "remaining backup belongs to publication",
        )
    unlink(pending)
    sync(runtime.directory)
    unlink(backup)


def finish(host, runtime, _data, work):
    with runtime_lock(host, runtime.user):
        snapshot = state(host, work)
        unchanged(host, runtime, snapshot, pool=False)
        check(
            file_hash(runtime.generated / "pool.conf") == snapshot["desired_hash"],
            "desired pool remains current",
        )
        check(
            host.active(runtime.service) == snapshot["active"],
            "service state remains current",
        )
        if snapshot["changed"] and snapshot["phase"] != "complete":
            journal_owned(host, runtime, snapshot)
            if snapshot["active"]:
                confirm(host, runtime, (runtime.generated / "pool.conf").read_bytes())
        snapshot["phase"] = "complete"
        save(work, snapshot)
        if snapshot["changed"]:
            clear_journal(host, runtime, snapshot)
        result = inspect(
            {
                "user": runtime.user,
                "version": runtime.version,
                "marker": runtime.expected.decode(),
            },
            host,
        )
    return {"ready": True, **view(snapshot), "fingerprint": result["fingerprint"]}


def restore_pool(host, runtime, _data, work):
    if not exists(work):
        return {"restored": False}
    with runtime_lock(host, runtime.user):
        snapshot = state(host, work)
        unchanged(host, runtime, snapshot, pool=False)
        if snapshot["phase"] not in ("publishing", "published", "restoring"):
            return {"restored": False}
        if snapshot["phase"] == "publishing" and not exists(
            runtime.receipt(METRICS_PENDING)
        ):
            check(
                file_hash(runtime.generated / "pool.conf") == snapshot["prior_hash"],
                "pool unchanged before journal publication",
            )
            backup = runtime.receipt(METRICS_BACKUP)
            if exists(backup):
                trusted(host, backup, 0o600)
                check(
                    file_hash(backup) == snapshot["prior_hash"],
                    "owned partial journal backup",
                )
                unlink(backup)
            snapshot["phase"] = "restored"
            save(work, snapshot)
            return {"restored": False}
        journal_owned(host, runtime, snapshot)
        current = file_hash(runtime.generated / "pool.conf")
        check(
            current in (snapshot["desired_hash"], snapshot["prior_hash"]),
            "refuse to restore over another publication",
        )
        publish(
            runtime.generated,
            runtime.generated / "pool.conf",
            runtime.receipt(METRICS_BACKUP).read_bytes(),
            0o644,
        )
        sync(runtime.generated)
        snapshot["phase"] = "restoring"
        save(work, snapshot)
    return {"restored": True}


def reload_restored(host, runtime, _data, work):
    if not exists(work):
        return {"reloaded": False}
    with runtime_lock(host, runtime.user):
        snapshot = state(host, work)
        unchanged(host, runtime, snapshot, pool=False)
        if snapshot["phase"] != "restoring":
            return {"reloaded": False}
        journal_owned(host, runtime, snapshot)
        check(
            file_hash(runtime.generated / "pool.conf") == snapshot["prior_hash"],
            "restored pool remains current",
        )
        check(
            host.active(runtime.service) == snapshot["active"],
            "service state remains current",
        )
        if snapshot["active"]:
            reload(host, runtime)
    return {"reloaded": snapshot["active"]}


def finish_restore(host, runtime, _data, work):
    if not exists(work):
        return {"restored": False}
    with runtime_lock(host, runtime.user):
        snapshot = state(host, work)
        unchanged(host, runtime, snapshot, pool=False)
        if snapshot["phase"] != "restoring":
            return {"restored": False}
        journal_owned(host, runtime, snapshot)
        check(
            file_hash(runtime.generated / "pool.conf") == snapshot["prior_hash"],
            "restored pool remains current",
        )
        if snapshot["active"]:
            confirm(host, runtime, (runtime.generated / "pool.conf").read_bytes())
        snapshot["phase"] = "restored"
        save(work, snapshot)
        clear_journal(host, runtime, snapshot)
    return {"restored": True}


def inspect_pool(host, runtime, _data, _work):
    if not regular(runtime.marker) or not directory(runtime.generated):
        return {"present": False}
    result = inspect(
        {
            "user": runtime.user,
            "version": runtime.version,
            "marker": runtime.expected.decode(),
        },
        host,
    )
    enabled = "pm.status_path = /orbit-fpm-status" in result["pool"].splitlines()
    return {"present": True, "enabled": enabled, "fingerprint": result["fingerprint"]}


def cleanup(host, runtime, _data, work):
    if not exists(work):
        return {"cleaned": True}
    snapshot = state(host, work)
    check(
        snapshot["phase"] in ("prepared", "complete", "restored"),
        "publication recovery complete before cleanup",
    )
    if snapshot["phase"] in ("complete", "restored"):
        with runtime_lock(host, runtime.user):
            unchanged(host, runtime, snapshot, pool=False)
            check(
                file_hash(runtime.generated / "pool.conf")
                == (
                    snapshot["desired_hash"]
                    if snapshot["phase"] == "complete"
                    else snapshot["prior_hash"]
                ),
                "completed pool remains current",
            )
            if snapshot["changed"]:
                clear_journal(host, runtime, snapshot)
    for path in work.iterdir():
        check(
            regular(path) and path.stat().st_uid == host.root_uid,
            "only owned staged files",
        )
    shutil.rmtree(work)
    return {"cleaned": True}


def execute(action):
    try:
        result = action(*context())
    except Refused as error:
        result = {"error_code": error.code}
    except Exception:
        result = {"error_code": "php.runtime_execution_failed"}
    print(json.dumps(result))
    return 1 if "error_code" in result else 0
