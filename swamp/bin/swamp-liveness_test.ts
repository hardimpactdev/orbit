import { assertEquals } from "jsr:@std/assert@1.0.19";
import { evaluate, python } from "./swamp-liveness_fixture.ts";

Deno.test("malformed probe responses are refused", async () => {
    const result = await python(`
values=[None,[],{},{"data":[]},{"data":{"kinds":[None]}},{"data":{"kinds":[{"kind":"test.kind","claimable":1,"oldest_at":None}]}},{"data":{"kinds":[{"kind":"test.kind","claimable":True}]}}]
print(json.dumps([[error(lambda:liveness.claimable_heads(v)) for v in values],[liveness.health_available(v) for v in [None,[],{},{"status":"starting"},{"status":"ok"}]]]))`);
    assertEquals(result, [Array(7).fill("ValueError"), [
        false,
        false,
        false,
        false,
        true,
    ]]);
});

Deno.test("duplicate summary kinds are refused", async () => {
    assertEquals(
        await python(`
entry={"kind":"test.kind","claimable":0,"oldest_at":None}
print(json.dumps(error(lambda:liveness.claimable_heads({"data":{"kinds":[entry,entry]}}))))`),
        "ValueError",
    );
});

Deno.test("partial or invalid state is preserved and rebaselines without restart", async () => {
    const result = await python(String.raw`
with tempfile.TemporaryDirectory() as temp:
 path=Path(temp)/'state.json'; results=[]
 for malformed in ['{"last_restart":','{"last_restart":"oops"}','{"last_restart":NaN}','{"claimable_heads":[]}']:
  path.write_text(malformed)
  # read_state logs diagnostics; keep the JSON driver output separate.
  from contextlib import redirect_stdout
  import io
  with redirect_stdout(io.StringIO()): state=liveness.read_state(path,2000)
  results.append([state,path.exists()])
 print(json.dumps([results,len(list(Path(temp).glob('state-invalid-*')))]))`);
    assertEquals(result, [Array(4).fill([{ last_restart: 2000 }, false]), 4]);
});

Deno.test("failed state publication keeps previous cooldown", async () => {
    assertEquals(
        await python(`
with tempfile.TemporaryDirectory() as temp:
 path=Path(temp)/'state.json'
 liveness.write_state(path,{"last_restart":1000})
 with patch.object(liveness.os,'replace',side_effect=OSError('ENOSPC')):
  failure=error(lambda:liveness.write_state(path,{"last_restart":2000}))
 print(json.dumps([failure,json.loads(path.read_text()),[p.name for p in Path(temp).iterdir()]]))`),
        ["OSError", { last_restart: 1000 }, ["state.json"]],
    );
});

Deno.test("old but draining backlog does not restart", async () => {
    assertEquals(
        await python(
            evaluate + `
_,state=evaluate()
print(json.dumps(evaluate(state,now=2200,heads={"fleet.peer_dns":1100})[0]["action"]))`,
        ),
        "healthy",
    );
});

Deno.test("three minutes without claim progress restarts", async () => {
    assertEquals(
        await python(
            evaluate + `
_,state=evaluate()
print(json.dumps(evaluate(state,now=2180)[0]["action"]))`,
        ),
        "restart",
    );
});

Deno.test("transient health failure is not a restart", async () => {
    assertEquals(
        await python(
            evaluate + `
_,state=evaluate(healthy=False)
print(json.dumps([evaluate(state,healthy=False,now=t)[0]["action"] for t in [2179,2180]]))`,
        ),
        ["healthy", "restart"],
    );
});

Deno.test("inode exhaustion alerts and does not restart", async () => {
    assertEquals(
        await python(
            evaluate + `
_,state=evaluate()
result,_=evaluate(state,now=2180,free_inodes=0)
print(json.dumps([result["action"],result["inode_alert"]]))`,
        ),
        ["inode_exhaustion", "critical"],
    );
});

Deno.test("inode warning is visible before exhaustion", async () => {
    assertEquals(
        await python(
            evaluate + `
print(json.dumps([evaluate(free_inodes=200)[0]["inode_alert"],"inode_alert" in evaluate(free_inodes=201)[0]]))`,
        ),
        ["warning", false],
    );
});

Deno.test("unavailable Gateway does not prove a stall", async () => {
    assertEquals(
        await python(
            evaluate + `
result,state=evaluate({"unchanged_since":1,"claimable_heads":{"fleet.peer_dns":1000}},heads=None)
print(json.dumps([result["action"],"unchanged_since" in state]))`,
        ),
        ["healthy", false],
    );
});

Deno.test("startup and restart cooldown", async () => {
    assertEquals(
        await python(
            evaluate + `
_,state=evaluate()
startup=evaluate(state,now=2180,serve_age=30)[0]["action"]
state["last_restart"]=2100
print(json.dumps([startup,evaluate(state,now=2180)[0]["action"]]))`,
        ),
        ["startup_grace", "restart_cooldown"],
    );
});
