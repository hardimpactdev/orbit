"""Existing normal-load preservation, health and production/QUIET guards."""

import datetime
import json
import ssl
import urllib.request


def configs(L):
    paths = [
        L.APP / ".swamp.yaml",
        L.APP / ".swamp/serve.yaml",
        *sorted((L.APP / "vaults").rglob("*.yaml")),
    ]
    return {str(p.relative_to(L.APP)): L.digest(p) for p in paths if p.is_file()}


def pauses(L):
    return {
        v["workflowId"]: v.get("paused")
        for v in L.native("workflow", "schedule", "list", remote=True).get(
            "schedules", []
        )
    }


def health():
    ctx = ssl.create_default_context(
        cafile="/usr/local/share/ca-certificates/orbit-root.crt"
    )
    for url in ["http://127.0.0.1:9418/health", "https://swamp.orbit/health"]:
        with urllib.request.urlopen(
            url, context=ctx if url.startswith("https") else None, timeout=3
        ) as r:
            assert r.status == 200 and json.load(r).get("status") == "ok", (
                "health_not_ok"
            )


PROTECTED_PRODUCTION_WORKFLOWS = {"automatic-production-deploy"}
PROTECTED_PRODUCTION_RUN_IDS = {"8f8478ee-880b-427e-a8c8-a101b4b29185"}
_LAST_DRAIN_DECISION = {}


def drain_reply(reply, quiet=None):
    # Direct Orchestrator standing rule: only production deploys and QUIET block.
    # Other interruption is accepted while Lab4223 remains unresolved.
    global _LAST_DRAIN_DECISION
    assert reply.get("type") == "run.history", "native_drain_status_unavailable"
    runs = reply.get("payload", {}).get("runs")
    assert isinstance(runs, list), "native_drain_status_incomplete"
    assert all(
        isinstance(r, dict)
        and r.get("status") == "running"
        and isinstance(r.get("id"), str)
        for r in runs
    ), "native_drain_status_incomplete"
    public = lambda row: {
        k: row.get(k) for k in ["id", "runKind", "workflowName", "status", "stale"]
    }
    protected = set()
    for row in runs:
        name = row.get("workflowName")
        inputs = row.get("inputs") if isinstance(row.get("inputs"), dict) else {}
        production = (
            row["id"] in PROTECTED_PRODUCTION_RUN_IDS
            or name in PROTECTED_PRODUCTION_WORKFLOWS
            or (
                name == "operation-instance-deploy-direct"
                and (
                    inputs.get("environment") == "production"
                    or inputs.get("account") == "production"
                )
            )
        )
        if production:
            protected.add(row["id"])
    changed = True
    while changed:
        changed = False
        for row in runs:
            parent = row.get("parentRunId") or row.get("parent_run_id")
            root = row.get("rootRunId") or row.get("root_run_id")
            if row["id"] not in protected and (
                parent in protected
                or root in protected
                or parent in PROTECTED_PRODUCTION_RUN_IDS
                or root in PROTECTED_PRODUCTION_RUN_IDS
            ):
                protected.add(row["id"])
                changed = True
    quiet_active = isinstance(quiet, dict) and quiet.get("state") == "QUIET_HELD"
    blocked = [public(row) for row in runs if quiet_active or row["id"] in protected]
    accepted = [
        public(row) for row in runs if not quiet_active and row["id"] not in protected
    ]
    _LAST_DRAIN_DECISION = {
        "at": datetime.datetime.now(datetime.UTC).isoformat(),
        "policy": "production_deploys_and_quiet_only",
        "lab_issue": 4223,
        "active_quiet_record": quiet_active,
        "protected_runs": blocked,
        "accepted_interruption_runs": accepted,
        "native_terminal_inferred": False,
    }
    if blocked or quiet_active:
        return {
            "state": "restart_deferred_active_runs",
            "active_runs": blocked,
            "accepted_interruption_runs": accepted,
            "policy": "production_deploys_and_quiet_only",
            "active_quiet_record": quiet_active,
        }
    return None


def drain_before_restart(L):
    # The existing official admin run tracker includes workflow and method runs.
    # A refusal or unreadable result never counts as a drained boundary.
    js = """const token=(await Deno.readTextFile(Deno.args[0])).trim();
const ws=new WebSocket('ws://127.0.0.1:9418',{headers:{Authorization:`Bearer ${token}`}});
let timer;try{const response=await new Promise((resolve,reject)=>{timer=setTimeout(()=>reject(new Error('native_drain_status_timeout')),20000);ws.addEventListener('error',()=>reject(new Error('native_drain_status_unavailable')),{once:true});const id=crypto.randomUUID();ws.addEventListener('open',()=>ws.send(JSON.stringify({type:'run.history',id,payload:{active:true}})),{once:true});ws.addEventListener('message',e=>{const r=JSON.parse(e.data);if(r.id===id)resolve(r);});});console.log(JSON.stringify(response));}finally{clearTimeout(timer);ws.close();}"""
    reply = json.loads(
        L.command(
            [
                str(L.HOME / ".swamp/deno/deno"),
                "eval",
                "--quiet",
                js,
                str(L.HOME / ".config/swamp/serve-driver-token"),
            ],
            owner=True,
            timeout=25,
        )
    )
    quiet_path = L.STATE / "quiet-window.json"
    quiet = json.loads(quiet_path.read_text()) if quiet_path.exists() else None
    decision = drain_reply(reply, quiet)
    L.atomic(L.STATE / "last-load-drain-decision.json", _LAST_DRAIN_DECISION)
    return decision
