"""Normal topology CLI and public readback checks; no admissions in parity."""

import json
import os
import re
import shlex
import subprocess
from pathlib import Path

KINDS = Path(__file__).parent


def exec_node(role, argv):
    assert role in {"operator", "gateway", "app-dev", "spare", "spare-2"}
    result = subprocess.run(
        [
            *shlex.split(os.environ["PROOF_EXEC"]),
            role,
            "--argv=" + json.dumps(argv),
            "--json",
        ],
        capture_output=True,
        text=True,
        check=True,
        timeout=120,
    )
    payload = json.loads(result.stdout)
    assert payload["exit_code"] == 0, payload.get("stderr")
    return json.loads(payload["stdout"])


def orbit(*argv):
    return exec_node("operator", ["orbit", *map(str, argv), "--json"])


def read_node(role, domain, leaves=(), https_address=None, cleanup=()):
    source = (KINDS / "target.py").read_text()
    return exec_node(
        role,
        [
            "sudo",
            "-n",
            "/usr/bin/python3",
            "-I",
            "-c",
            source,
            json.dumps(
                {
                    "domain": domain,
                    "leaves": leaves,
                    "cleanup": cleanup,
                    "https_address": https_address,
                }
            ),
        ],
    )


def addresses(node):
    return {value for key in ("wireguard_ip", "lan_ip") if (value := node.get(key))}


def assert_selected(view, domain, node, serves):
    assert view["dns"] and set(view["dns"]) <= addresses(node), (
        "DNS did not select the expected Router"
    )
    assert (domain in view["hosts"]) is serves
    if serves:
        assert view["active"] and view["loaded_matches_disk"]
        assert view["https"] == "router-proof-ok\n"


def check_terminal(operation, native, context):
    assert operation["kind"] == "cluster.router_set"
    assert operation["target_type"] == "cluster_router"
    assert operation["target_id"] == context["cluster_id"]
    assert operation["status"] == "succeeded" and operation["error_code"] is None
    assert not operation["cancel_requested"]
    assert operation["result"] == native
    assert native["ready"] is True and native["selected"] is True
    assert native["restored"] is False and native["cleanup_complete"] is True
    assert native.get("failed_step") is None and native.get("error_code") is None
    intent = operation["input"]
    assert intent["node_id"] == context["new"]["id"]
    assert intent["old_node_id"] == context["old"]["id"]
    assert intent["cluster_router"]["candidate"]["node_id"] == context["new"]["id"]
    assert intent["cluster_router"]["noop"] is False
    assert context["route_id"] in intent["cluster_router"]["route_ids"]


def baseline_path(variant):
    assert variant in {"01-forward", "02-return"}
    return Path(os.environ["PROOF_KIND_DIR"]) / (
        variant + "-" + os.environ["PROOF_RUN"] + "-baseline.json"
    )


def caddy_sections(text):
    markers = list(re.finditer(r"^# orbit: ([^\s]+) ([^\r\n]+)\n", text, re.MULTILINE))
    groups = {}
    for index, marker in enumerate(markers):
        end = markers[index + 1].start() if index + 1 < len(markers) else len(text)
        groups.setdefault(marker.groups(), []).append(
            text[marker.start() : end].rstrip("\n") + "\n"
        )
    return text[: markers[0].start() if markers else len(text)].rstrip("\n"), groups


def dns_records(text):
    result = {}
    for line in text.splitlines():
        if not line or line.startswith("#"):
            continue
        match = re.fullmatch(
            r"(host-record)=([^,]+),.+|(address|local)=/([^/]+)/.*", line
        )
        assert match, "Unexpected DNS record"
        key = match.group(1, 2) if match[1] else match.group(3, 4)
        assert key not in result, "Duplicate DNS record"
        result[key] = line
    return result


def catalog_leaves(value, path=()):
    if isinstance(value, dict):
        return {
            key: leaf
            for name, child in value.items()
            for key, leaf in catalog_leaves(child, (*path, name)).items()
        }
    return {} if value == [] else {path: value}


def assert_delta(before, after, baseline, observed):
    expected = {key: value for key, value in baseline.items() if key not in before}
    assert observed == {**expected, **after}, "Affected or retained entry differs"


def assert_publication(publication, artifacts, baseline, observed):
    def artifact(phase, kind):
        index = publication[phase][kind]
        assert type(index) is int and 0 <= index < len(artifacts[kind])
        return artifacts[kind][index]

    before, after = artifact("before", "caddy"), artifact("after", "caddy")
    header, desired = caddy_sections(after["content"])
    actual_header, actual = caddy_sections(observed["caddy"])
    assert actual_header == header, "Caddy global configuration differs"
    assert_delta(
        caddy_sections(before["content"])[1],
        desired,
        caddy_sections(baseline["caddy"])[1],
        actual,
    )
    if publication["after"]["dns"] is not None:
        before, after = artifact("before", "dns"), artifact("after", "dns")
        for field, parse in [
            ("records", dns_records),
            ("catalog", lambda text: catalog_leaves(json.loads(text or "{}"))),
        ]:
            assert_delta(
                parse(before[field]),
                parse(after[field]),
                parse(baseline["dns_" + field]),
                parse(observed["dns_" + field]),
            )
