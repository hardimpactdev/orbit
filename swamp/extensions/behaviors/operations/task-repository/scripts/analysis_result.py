"""Validate the bounded analyzer report and shape immutable native provenance."""

import hashlib
import json
import math
import os

from repository_common import Refusal, emit, redact_text

MAX_REPORT = 65536


def strict_json(raw):
    def fields(pairs):
        result = {}
        for key, value in pairs:
            if key in result:
                raise ValueError("Duplicate JSON field")
            result[key] = value
        return result

    def constant(_):
        raise ValueError("Non-finite JSON number")

    value = json.loads(
        raw.decode("utf-8", errors="strict"),
        object_pairs_hook=fields,
        parse_constant=constant,
    )

    def depth(item, level):
        if level > 24 or isinstance(item, float) and not math.isfinite(item):
            raise ValueError("Invalid JSON depth or number")
        if isinstance(item, str):
            item.encode("utf-8", errors="strict")
        if isinstance(item, dict):
            for key in item:
                key.encode("utf-8", errors="strict")
        if isinstance(item, (dict, list)):
            for child in item.values() if isinstance(item, dict) else item:
                depth(child, level + 1)

    depth(value, 1)
    return value


def object_fields(value, fields):
    if not isinstance(value, dict) or set(value) != set(fields):
        raise ValueError("Invalid object fields")
    return value


def report(raw, identity, code):
    if len(raw) > MAX_REPORT:
        return {
            "error_code": "tasks.analysis_report_too_large",
            "schema": 1,
            "error": {
                "code": "tasks.analysis_report_too_large",
                "required_bytes": None,
            },
        }
    value = strict_json(raw)
    if code != 0 and isinstance(value, dict) and "error" in value:
        object_fields(value, ["schema", "error"])
        error = object_fields(value["error"], ["code", "required_bytes"])
        if (
            not isinstance(value["schema"], int) or isinstance(value["schema"], bool)
        ) or value["schema"] != 1:
            raise ValueError("Invalid version")
        if (
            error["code"] == "tasks.analysis_unavailable"
            and error["required_bytes"] is None
        ):
            return {**value, "error_code": error["code"]}
        if (
            error["code"] == "tasks.analysis_report_too_large"
            and isinstance(error["required_bytes"], int)
            and not isinstance(error["required_bytes"], bool)
            and MAX_REPORT < error["required_bytes"] <= 9007199254740991
        ):
            return {**value, "error_code": error["code"]}
        raise ValueError("Invalid failure")
    object_fields(
        value,
        [
            "schema",
            "execution_id",
            "plan_sha256",
            "base",
            "head",
            "tree",
            "status",
            "diagnostics",
            "report",
        ],
    )
    if (
        (not isinstance(value["schema"], int) or isinstance(value["schema"], bool))
        or value["schema"] != 1
        or value["status"] not in ("ready", "action_required", "blocked")
    ):
        raise ValueError("Invalid report version or status")
    for key in ("execution_id", "plan_sha256", "base", "head", "tree"):
        if value[key] != identity[key]:
            raise ValueError("Different report identity")
    if not isinstance(value["diagnostics"], list):
        raise TypeError("Invalid diagnostics")
    for item in value["diagnostics"]:
        object_fields(item, ["code", "message"])
        if (
            not isinstance(item["code"], str)
            or not item["code"].strip()
            or not isinstance(item["message"], str)
        ):
            raise ValueError("Invalid diagnostic")
    data = object_fields(value["report"], ["schema", "data"])
    if (
        not isinstance(data["schema"], str)
        or not data["schema"].strip()
        or not isinstance(data["data"], dict)
    ):
        raise ValueError("Invalid repository report")
    # Structured evidence must never be silently altered by redaction.
    serialized = json.dumps(value, ensure_ascii=False)
    if (
        redact_text(raw.decode()) != raw.decode()
        or redact_text(serialized) != serialized
    ):
        raise ValueError("Private report data")
    return value


def result():
    value = json.loads(os.environ["ORBIT_INPUT"])
    before, after, execution, prepared = (
        json.loads(os.environ[key])
        for key in ("ORBIT_BEFORE", "ORBIT_AFTER", "ORBIT_EXECUTION", "ORBIT_PREPARED")
    )
    for item in (prepared, before, execution, after):
        if "error_code" in item:
            return item
    identity = {key: value[key] for key in ("plan_sha256", "base", "head", "tree")}
    identity["execution_id"] = execution["execution_id"]
    raw = execution["report"].encode()
    try:
        parsed = report(raw, identity, execution["exit_code"])
    except (ValueError, TypeError, UnicodeError):
        raise Refusal("tasks.analysis_report_invalid") from None
    if "error_code" in parsed:
        return parsed
    if before == after and execution["exit_code"] != 0:
        raise Refusal("tasks.analysis_failed")
    return {
        "outcome": "changed" if before != after else parsed["status"],
        "artifact": {
            "sha256": hashlib.sha256(raw).hexdigest(),
            "bytes": len(raw),
            "content": execution["report"],
        },
        "provenance": {
            "operation_id": value["operation_id"],
            "group": value["group_id"],
            "subtask": value["subtask"],
            "repository": value["repository"],
            "branch": value["branch"],
            "instance_id": value["instance_id"],
            "node_id": value["node_id"],
            "plan_id": value["plan"]["id"],
            "plan_revision": value["plan"]["revision"],
            "plan_sha256": value["plan_sha256"],
            "base": value["base"],
            "head": before["head"],
            "tree": before["tree"],
            "before": before,
            "after": after,
            "execution_id": execution["execution_id"],
            "entrypoint_sha256": prepared["entrypoint_sha256"],
            **{
                key: execution[key]
                for key in ("started_at", "finished_at", "duration_ms", "exit_code")
            },
            "mechanical_llm_calls": 0,
        },
    }


if __name__ == "__main__":
    emit(result)
