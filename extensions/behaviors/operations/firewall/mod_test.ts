import { assertEquals, assertRejects } from "jsr:@std/assert@1.0.19";
import { createModelTestContext } from "jsr:@swamp-club/swamp-testing@0.20261016.58";
import { firewall } from "./mod.ts";
import {
  type Context,
  Operation,
  operationDiscovery,
} from "../../operation-runner/mod.ts";
import type { Http } from "../../automatic-deploy/gateway.ts";

class Fixture {
  operation = Operation.parse({
    id: 7,
    node_id: 2,
    kind: "firewall.allow",
    status: "running",
    cancel_requested: false,
    input: {
      node_id: 2,
      name: "proof",
      action: "allow",
      source: "192.0.2.1/32",
      protocol: "tcp",
      port: "65431",
    },
    step_results: {},
  });
  calls: {
    method: string;
    peer: number;
    path: string;
    body: Record<string, unknown>;
  }[] = [];
  peer = 12;
  leasePeer: number | null = null;
  leaseWorker: string | null = null;
  leaseExpired = false;
  noClaim = false;
  claimableCount = 1;
  runs = 0;
  failAfterRun = false;
  cancelAtRun = false;
  failCompletion = false;
  commitThenLoseCompletion = false;
  queuedNext = false;
  terminalResult: Record<string, unknown> | null = null;
  terminalError: string | null = null;
  backend = "active";
  runReceipt: Record<string, unknown> | null = null;
  resources = new Map<string, Record<string, unknown>>();
  http: Http = async (method, path, raw) => {
    const body = (raw ?? {}) as Record<string, unknown>;
    this.calls.push({ method, peer: this.peer, path, body });
    await Promise.resolve();
    let data: unknown = this.operation;
    if (path === `/api/v1/operations/${this.operation.id}`) {
      return {
        status: 200,
        body: {
          data: {
            ...this.operation,
            result: this.terminalResult &&
                Object.keys(this.terminalResult).length === 0
              ? []
              : this.terminalResult ?? this.runReceipt,
            error_code: this.terminalError,
          },
        },
      };
    }
    if (path.endsWith("/claimable")) {
      assertEquals(method, "GET");
      return {
        status: 200,
        body: {
          data: {
            kinds: [{
              kind: this.operation.kind,
              claimable: this.claimableCount,
              oldest_at: "2026-10-29T00:00:00Z",
            }],
          },
        },
      };
    }
    if (path.endsWith("/claim")) {
      if (this.queuedNext && this.operation.status === "succeeded") {
        this.operation = {
          ...this.operation,
          id: 8,
          status: "running",
          step_results: {},
        };
      }
      if (
        this.noClaim || body.kind !== this.operation.kind ||
        this.leasePeer !== null && !this.leaseExpired &&
          (this.peer !== this.leasePeer || body.worker !== this.leaseWorker)
      ) data = null;
      else {
        this.leasePeer = this.peer;
        this.leaseWorker = String(body.worker);
        this.leaseExpired = false;
      }
    } else if (this.peer !== this.leasePeer) {
      return { status: 409, body: { error: { code: "operation.lease_lost" } } };
    }
    if (path.endsWith("/run")) {
      if (!this.runReceipt) {
        if (this.cancelAtRun) {
          this.runReceipt = { error_code: "operation.cancelled" };
        } else {
          this.runs++;
          this.runReceipt = { backend: this.backend };
        }
      }
      if (this.failAfterRun) {
        this.failAfterRun = false;
        throw new Error("Lost run response");
      }
      data = this.runReceipt;
    }
    if (path.endsWith("/step") && body.status === "succeeded") {
      this.operation.step_results[String(body.name)] = body.result;
    }
    if (path.endsWith("/complete") && this.failCompletion) {
      this.failCompletion = false;
      throw new Error("Lost completion response");
    }
    if (path.endsWith("/complete") && this.commitThenLoseCompletion) {
      this.operation.status = String(body.status);
      this.terminalResult = body.result as Record<string, unknown>;
      this.terminalError = body.error_code as string | null;
      this.commitThenLoseCompletion = false;
      throw new Error("Committed completion reply lost");
    }
    return { status: 200, body: { data } };
  };
  context(): Context {
    const { context } = createModelTestContext({
      globalArgs: {
        gatewayUrl: "https://gateway.example.test",
        caFile: "/unused",
      },
    });
    const write = context.writeResource.bind(context);
    return Object.assign(context, {
      globalArgs: {
        gatewayUrl: "https://gateway.example.test",
        caFile: "/unused",
      },
      readResource: (name: string) =>
        Promise.resolve(this.resources.get(name) ?? null),
      writeResource: async (
        spec: string,
        name: string,
        value: Record<string, unknown>,
      ) => {
        const handle = await write(spec, name, value);
        this.resources.set(name, structuredClone(value));
        return handle;
      },
    });
  }
  execute(worker = "run-1") {
    return firewall(() => this.http).methods.execute.execute({
      kind: this.operation.kind,
      worker,
    }, this.context());
  }
}
Deno.test("Family discovery is read-only and selects only its own kinds without ids or inputs", async () => {
  const f = new Fixture();
  const runner = operationDiscovery(() => f.http);
  const before = structuredClone(f.operation);
  f.peer = 17;
  const result = await runner.methods.discover.execute(
    {
      family: "operation-firewall",
      kinds: ["firewall.allow", "firewall.deny", "firewall.remove"],
      worker: "workflow-7",
    },
    f.context(),
  );
  assertEquals(result.work.length, 1);
  assertEquals(result.work[0].worker, "workflow-7:firewall.allow:0");
  assertEquals(result.work[0], {
    kind: "firewall.allow",
    slot: 0,
    worker: "workflow-7:firewall.allow:0",
  });
  assertEquals(f.calls.map((c) => [c.method, c.path]), [[
    "GET",
    "/api/v1/operation-executor/claimable",
  ]]);
  assertEquals(f.operation, before);
  assertEquals(f.leasePeer, null);
  f.claimableCount = 0;
  assertEquals(
    (await runner.methods.discover.execute({
      family: "operation-firewall",
      kinds: ["firewall.allow"],
      worker: "empty",
    }, f.context()))
      .work,
    [],
  );
});
Deno.test("allow and deny converge exact recorded input and retain steps", async () => {
  for (const action of ["allow", "deny"] as const) {
    const f = new Fixture();
    f.operation.kind = `firewall.${action}`;
    f.operation.input.action = action;
    assertEquals((await f.execute()).status, "succeeded");
    const invocation = f.calls.find((call) => call.path.endsWith("/run"))!.body;
    assertEquals(invocation.name, "firewall.rule");
    assertEquals("node_id" in invocation, false);
    assertEquals(invocation.input, {
      ...f.operation.input,
      operation: "converge",
    });
    assertEquals(f.calls.at(-1)!.body.status, "succeeded");
  }
});
Deno.test("remove uses its exact owned identity and confirms absence", async () => {
  const f = new Fixture();
  f.operation.kind = "firewall.remove";
  f.backend = "absent";
  assertEquals((await f.execute()).status, "succeeded");
  assertEquals(
    (f.calls.find((call) => call.path.endsWith("/run"))!.body.input as Record<
      string,
      unknown
    >).operation,
    "remove",
  );
});
Deno.test("inactive UFW produces a failed step and stable error code", async () => {
  const f = new Fixture();
  f.backend = "inactive";
  assertEquals((await f.execute()).status, "failed");
  assertEquals(f.calls.at(-1)!.body.error_code, "firewall.backend_inactive");
  assertEquals(f.calls.at(-2)!.body.status, "failed");
});
Deno.test("restart after a lost run reply repeats the same effect key and resumes its receipt", async () => {
  const f = new Fixture();
  f.failAfterRun = true;
  await assertRejects(() => f.execute(), Error, "Lost run response");
  assertEquals(f.calls.some((call) => call.path.endsWith("/complete")), false);
  assertEquals((await f.execute()).status, "succeeded");
  assertEquals(f.runs, 1);
  assertEquals(
    f.calls.filter((call) => call.path.endsWith("/run")).map((call) =>
      call.body.key
    ),
    ["ufw-converge", "ufw-converge"],
  );
});
Deno.test("restart after a lost completion reply replays its saved intent without Node work", async () => {
  const f = new Fixture();
  f.failCompletion = true;
  await assertRejects(() => f.execute(), Error, "Lost completion response");
  const count = f.calls.length;
  assertEquals((await f.execute()).status, "succeeded");
  assertEquals(f.calls.length, count + 3);
  assertEquals(f.calls.at(-1)!.path.endsWith("/complete"), true);
  assertEquals(f.runs, 1);
});
Deno.test("successful step receipt skips Node work after a process restart", async () => {
  const f = new Fixture();
  f.operation.step_results["ufw-converge"] = { backend: "active" };
  assertEquals((await f.execute()).status, "succeeded");
  assertEquals(f.runs, 0);
});
Deno.test("cancellation before mutation records a cancelled receipt", async () => {
  const f = new Fixture();
  f.operation.cancel_requested = true;
  assertEquals((await f.execute()).status, "cancelled");
  assertEquals(f.runs, 0);
});

Deno.test("cancellation after a proven effect keeps its successful receipt", async () => {
  const f = new Fixture();
  f.operation.cancel_requested = true;
  f.operation.step_results["ufw-converge"] = { backend: "active" };
  assertEquals((await f.execute()).status, "succeeded");
  assertEquals(f.runs, 0);
});
Deno.test("cancellation after a started step reconciles its uncertain effect", async () => {
  const f = new Fixture();
  f.operation.cancel_requested = true;
  f.operation.steps = [{ name: "ufw-converge", status: "running" }];
  f.runReceipt = { backend: "active" };
  assertEquals((await f.execute()).status, "succeeded");
  assertEquals(f.runs, 0);
});

Deno.test("cancellation observed by the Gateway before invocation closes the step without Node work", async () => {
  const f = new Fixture();
  f.cancelAtRun = true;
  assertEquals((await f.execute()).status, "cancelled");
  assertEquals(f.runs, 0);
  assertEquals(f.calls.at(-2)!.body.status, "failed");
  assertEquals(f.calls.at(-1)!.body.error_code, "operation.cancelled");
});

Deno.test("the placed worker claims first and owns every effect and completion write", async () => {
  const f = new Fixture();
  await f.execute();
  assertEquals(f.calls[0].path, "/api/v1/operation-executor/claim");
  assertEquals(
    f.calls.every((call) => call.method === "POST" && call.peer === 12),
    true,
  );
  assertEquals(f.leasePeer, 12);
  f.peer = 13;
  const refused = await f.http(
    "POST",
    "/api/v1/operation-executor/7/heartbeat",
    { worker: "run-1" },
  );
  assertEquals(refused.status, 409);
});
Deno.test("a lost race to claim causes no heartbeat, effect or completion", async () => {
  const f = new Fixture();
  f.noClaim = true;
  assertEquals(await f.execute(), { claimed: false });
  assertEquals(f.calls.length, 1);
  assertEquals(f.calls[0].path, "/api/v1/operation-executor/claim");
  assertEquals(f.runs, 0);
});

Deno.test("a committed completion with a lost reply never claims the next Operation", async () => {
  const f = new Fixture();
  f.commitThenLoseCompletion = true;
  f.queuedNext = true;
  await assertRejects(
    () => f.execute(),
    Error,
    "Committed completion reply lost",
  );
  const count = f.calls.length;
  assertEquals(await f.execute(), {
    claimed: true,
    operationId: 7,
    status: "succeeded",
  });
  assertEquals(f.calls.slice(count).map((call) => [call.method, call.path]), [[
    "GET",
    "/api/v1/operations/7",
  ]]);
  assertEquals(f.operation.id, 7);
  assertEquals(f.runs, 1);
});
Deno.test("a retry on another Node cannot claim another Operation or write the old lease", async () => {
  const f = new Fixture();
  f.failAfterRun = true;
  await assertRejects(() => f.execute(), Error, "Lost run response");
  const claims = f.calls.filter((c) => c.path.endsWith("/claim")).length;
  f.peer = 13;
  await assertRejects(() => f.execute(), Error, "operation.lease_lost");
  assertEquals(f.calls.filter((c) => c.path.endsWith("/claim")).length, claims);
  assertEquals(f.runs, 1);
});

Deno.test("fresh discovery can reclaim an expired lease on a new Node without repeating the proven effect", async () => {
  const f = new Fixture();
  f.failAfterRun = true;
  await assertRejects(() => f.execute(), Error, "Lost run response");
  f.peer = 13;
  assertEquals(await f.execute("replacement"), { claimed: false });
  f.leaseExpired = true;
  assertEquals((await f.execute("replacement")).status, "succeeded");
  assertEquals(f.leasePeer, 13);
  assertEquals(f.runs, 1);
  f.peer = 12;
  assertEquals(
    (await f.http("POST", "/api/v1/operation-executor/7/heartbeat", {
      worker: "run-1",
    })).status,
    409,
  );
});

Deno.test("committed cancellation reads the Gateway empty-array result without claiming new work", async () => {
  const f = new Fixture();
  f.operation.cancel_requested = true;
  f.commitThenLoseCompletion = true;
  await assertRejects(
    () => f.execute(),
    Error,
    "Committed completion reply lost",
  );
  const count = f.calls.length;
  assertEquals(await f.execute(), {
    claimed: true,
    operationId: 7,
    status: "cancelled",
  });
  assertEquals(f.calls.slice(count).map((c) => c.path), [
    "/api/v1/operations/7",
  ]);
  assertEquals(f.runs, 0);
});
