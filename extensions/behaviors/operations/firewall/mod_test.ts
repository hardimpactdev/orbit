import { assertEquals, assertRejects } from "jsr:@std/assert@1.0.19";
import { createModelTestContext } from "jsr:@swamp-club/swamp-testing@0.20261016.58";
import { firewall } from "./mod.ts";
import {
  type Context,
  Operation,
  operationRunner,
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
  calls: { path: string; body: Record<string, unknown> }[] = [];
  runs = 0;
  failAfterRun = false;
  cancelAtRun = false;
  failCompletion = false;
  backend = "active";
  runReceipt: Record<string, unknown> | null = null;
  resources = new Map<string, Record<string, unknown>>();
  http: Http = async (_method, path, raw) => {
    const body = raw as Record<string, unknown>;
    this.calls.push({ path, body });
    await Promise.resolve();
    let data: unknown = this.operation;
    if (path.endsWith("/claim")) {
      data = body.kind === this.operation.kind ? this.operation : null;
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
  execute() {
    return firewall(() => this.http).methods.execute.execute({
      operationId: this.operation.id,
      worker: "run-1",
    }, this.context());
  }
}
Deno.test("runner claims each family kind with a stable workflow key", async () => {
  const f = new Fixture();
  const runner = operationRunner(
    () => f.http,
    () =>
      Promise.resolve([{
        workflow: "operation-firewall",
        kinds: ["firewall.allow", "firewall.deny", "firewall.remove"],
      }]),
  );
  const result = await runner.methods.claim.execute(
    { worker: "workflow-7" },
    f.context(),
  );
  assertEquals(result.claims.length, 1);
  assertEquals(result.claims[0].worker, "workflow-7:firewall.allow");
  assertEquals(result.claims[0].workflow, "operation-firewall");
  assertEquals(f.calls.length, 3);
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
  assertEquals(f.calls.length, count + 1);
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
