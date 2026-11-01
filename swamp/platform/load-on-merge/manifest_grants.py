"""Reconcile declared policy through native access commands; never read values."""

import datetime
import hashlib
import json
import os
import re
import subprocess

PRINCIPAL = "user:orbit-gateway"
SUBJECT = {"kind": "user", "name": "orbit-gateway"}


def canonical(value):
    return json.dumps(value, sort_keys=True, separators=(",", ":"))


def scopes(policy):
    if policy.get("version") != 2 or policy.get("principal") != PRINCIPAL:
        raise ValueError("unsupported_manifest")
    requested = []
    for row in policy["workflows"]:
        if row["actions"] != ["run", "read"] or not re.fullmatch(
            r"[a-z][a-z0-9_-]*", row["workflow"]
        ):
            raise ValueError("invalid_workflow_scope")
        requested.append(
            {
                "subject": SUBJECT,
                "effect": "allow",
                "actions": row["actions"],
                "resource": {"kind": "workflow", "pattern": row["workflow"]},
            }
        )
    for row in policy["vaults"]:
        key = row["key_pattern"]
        if (
            row["actions"] != ["write", "read"]
            or not re.fullmatch(r"[a-z][a-z0-9_-]*", row["vault"])
            or not key.startswith("^")
            or not key.endswith("$")
            or any(c in key for c in "\n\r")
        ):
            raise ValueError("invalid_vault_scope")
        re.compile(key)
        requested.append(
            {
                "subject": SUBJECT,
                "effect": "allow",
                "actions": row["actions"],
                "resource": {"kind": "vault", "pattern": row["vault"]},
                "condition": "key.matches('"
                + key.replace("\\", "\\\\").replace("'", "\\'")
                + "')",
            }
        )
    identities = [(canonical(r["resource"]), r.get("condition")) for r in requested]
    if not requested or len(identities) != len(set(identities)):
        raise ValueError("duplicate_or_empty_manifest")
    return requested


def same_scope(grant, want):
    return (
        grant.get("subject") == want["subject"]
        and grant.get("resource") == want["resource"]
        and (grant.get("condition") or None) == want.get("condition")
        and grant.get("state", "active") == "active"
    )


def missing_actions(grants, want):
    found = [g for g in grants if same_scope(g, want)]
    actions = []
    for grant in found:
        allowed = grant.get("actions")
        if (
            grant.get("effect") != "allow"
            or grant.get("methods")
            or not isinstance(allowed, list)
            or not allowed
            or not set(allowed).issubset(want["actions"])
        ):
            raise ValueError("existing_exact_scope_conflict")
        actions.extend(allowed)
    if len(actions) != len(set(actions)):
        raise ValueError("overlapping_exact_scope_conflict")
    return found, [a for a in want["actions"] if a not in actions]


def readback(grants, requested):
    result = []
    for want in requested:
        found, missing = missing_actions(grants, want)
        if missing:
            raise ValueError("exact_readback_failed")
        result.extend(found)
    return result


def registered_workflows(reply, requested):
    rows = reply.get("results")
    if not isinstance(rows, list):
        raise TypeError("unreadable_workflow_catalog")
    result = []
    for scope in requested:
        if scope["resource"]["kind"] != "workflow":
            continue
        name = scope["resource"]["pattern"]
        found = [r for r in rows if r.get("name") == name]
        if len(found) != 1 or not re.fullmatch(
            r"[a-f0-9]{8}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{12}",
            found[0].get("id", ""),
        ):
            raise ValueError("workflow_registration_unproved:" + name)
        result.append({"name": name, "id": found[0]["id"]})
    return result


def atomic(path, value):
    temporary = path.with_suffix(".tmp")
    with temporary.open("w") as stream:
        os.chmod(temporary, 0o600)
        json.dump(value, stream, indent=2)
        stream.write("\n")
        stream.flush()
        os.fsync(stream.fileno())
    temporary.replace(path)


def save(path, value):
    raw = (json.dumps(value, indent=2) + "\n").encode()
    with path.open("xb") as stream:
        os.chmod(path, 0o600)
        stream.write(raw)
        stream.flush()
        os.fsync(stream.fileno())
    return hashlib.sha256(raw).hexdigest()


def apply(raw, target, directory, native):
    """Return policy readback pending the configured Gateway-token proof.

    An applied checkpoint can continue with reads only. An uncertain native
    create or reload cannot be repeated by another normal load invocation.
    """
    if not re.fullmatch(r"[a-f0-9]{40}", target):
        raise ValueError("invalid_source_revision")
    requested = scopes(json.loads(raw))
    digest = hashlib.sha256(raw).hexdigest()
    directory.mkdir(parents=True, mode=0o700, exist_ok=True)
    checkpoint = directory / (target + ".json")
    previous = json.loads(checkpoint.read_text()) if checkpoint.exists() else None
    if previous and (
        previous["state"] not in {"applied", "verified"}
        or previous["manifest_sha256"] != digest
        or previous.get("pending_mutation")
    ):
        raise ValueError("checkpoint_requires_inspection")
    before = native(["access", "grant", "list"])
    groups = native(["access", "group", "list"])
    if not isinstance(before, list) or not isinstance(groups, list):
        raise TypeError("unreadable_policy_inventory")
    plan = [missing_actions(before, want) for want in requested]
    if previous and any(missing for _, missing in plan):
        raise ValueError("applied_policy_changed_requires_inspection")
    registered = registered_workflows(native(["workflow", "search"]), requested)
    stamp = datetime.datetime.now(datetime.UTC).strftime("%Y%m%dT%H%M%S%fZ")
    backup = directory / (target + "-" + stamp)
    backup.mkdir(mode=0o700)
    save(backup / "grants-before.json", before)
    save(backup / "groups-before.json", groups)
    save(backup / "source-manifest.json", json.loads(raw))
    if previous:
        save(backup / "previous-checkpoint.json", previous)
    out = {
        "state": "preflight",
        "target": target,
        "manifest_sha256": digest,
        "principal": PRINCIPAL,
        "backup_path": str(backup),
        "created": [],
        "requested": requested,
        "registered_workflows": registered,
        "token_unchanged": True,
        "values_read": False,
        "business_runs_started": 0,
        "runtime_actions": 0,
        "read_only_continuation": bool(previous),
    }
    atomic(checkpoint, out)
    try:
        for want, (_, missing) in zip(requested, plan):
            if not missing:
                continue
            out["pending_mutation"] = {"create": {**want, "actions": missing}}
            atomic(checkpoint, out)
            args = [
                "access",
                "grant",
                "create",
                "--subject",
                PRINCIPAL,
                "--allow",
                ",".join(missing),
                "--on",
                want["resource"]["kind"] + ":" + want["resource"]["pattern"],
            ]
            if want.get("condition"):
                args += ["--when", want["condition"]]
            if native(args).get("status") != "succeeded":
                raise ValueError("grant_create_unconfirmed")
            out["created"].append({**want, "actions": missing})
            out.pop("pending_mutation")
            atomic(checkpoint, out)
        after = native(["access", "grant", "list"])
        if (
            not isinstance(after, list)
            or any(g not in after for g in before)
            or len(after) != len(before) + len(out["created"])
            or sorted(map(canonical, native(["access", "group", "list"])))
            != sorted(map(canonical, groups))
        ):
            raise ValueError("prior_policy_changed")
        out["grants"] = readback(after, requested)
        out.update(
            previous_grants_unchanged=True,
            groups_unchanged=True,
            before_count=len(before),
            after_count=len(after),
        )
        save(backup / "grants-after.json", after)
        if out["created"]:
            out["pending_mutation"] = {"reload": True}
            atomic(checkpoint, out)
            if native(["access", "reload"]).get("success") is not True:
                raise ValueError("reload_unconfirmed")
            out.pop("pending_mutation")
        out["state"] = "applied"
        atomic(checkpoint, out)
        return out
    except Exception:
        out["state"] = "failed_requires_inspection"
        atomic(checkpoint, out)
        raise


def native(args, cwd):
    env = {
        "HOME": "/home/orbit",
        "PATH": "/home/orbit/.local/bin:/usr/local/bin:/usr/bin:/bin",
        "SWAMP_HOME": "/home/orbit/.swamp",
        "SWAMP_CONFIG_DIR": "/home/orbit/.config/swamp",
        "SWAMP_API_KEY_FILE": "/etc/orbit/swamp-issuer/api-key",
        "SWAMP_NO_UPDATE_CHECK": "1",
        "SWAMP_NO_TELEMETRY": "1",
        "SWAMP_SERVE_TIMEOUT_MS": "60000",
    }
    result = subprocess.run(
        [
            "/home/orbit/.local/bin/swamp",
            *args,
            "--server",
            "ws://127.0.0.1:9418",
            "--token-file",
            "/etc/orbit/swamp-issuer/operator.token",
            "--json",
        ],
        cwd=cwd,
        env=env,
        check=False,
        capture_output=True,
        timeout=90,
    )
    if result.returncode or len(result.stdout) > 8 * 1024 * 1024:
        raise RuntimeError("native_policy_refused_or_uncertain")
    text = result.stdout.decode()
    values = []
    while text.strip():
        value, end = json.JSONDecoder().raw_decode(text.lstrip())
        values.append(value)
        text = text.lstrip()[end:]
    if not values:
        raise ValueError("empty_native_policy_reply")
    return values[-1]
