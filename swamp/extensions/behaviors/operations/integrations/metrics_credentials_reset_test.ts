import { assertEquals, assertRejects } from "jsr:@std/assert@1.0.19";
import type { Http } from "../../automatic-deploy/gateway.ts";
import type { Context } from "./metrics_credentials_reset.ts";
import { metricsCredentialsReset } from "./metrics_credentials_reset.ts";
const args = {
  operation_id: 17,
  kind: "metrics.credentials_reset" as const,
  node_id: 17,
  run_id: "a075eb23-7ac4-4487-80d1-57a038a92676",
};
const context = {
  globalArgs: { gatewayUrl: "https://gateway.orbit", caFile: "/test/ca.pem" },
} as Context;
function fixture() {
  const f = {
    operation: {
      ...args,
      id: 17,
      status: "queued",
      result: null as unknown,
      cancel_requested: false,
      input: { address: "10.44.0.17", agent_sha256: "a".repeat(64) },
    },
    applied: false,
    refuse: false,
    uncertain: false,
    unverified: false,
    lose: false,
    bound: true,
    calls: [] as string[],
    projections: [] as unknown[],
    actions: [] as string[],
  };
  const http: Http = (method, path, body) =>
    Promise.resolve().then(() => {
      f.calls.push(path);
      if (!f.bound) {
        return {
          status: 403,
          body: { error: { code: "operation.run_mismatch" } },
        };
      }
      if (path.includes("/private-material")) {
        return {
          status: 200,
          body: {
            data: {
              credential: {
                active_password: "ACTIVE_SENTINEL",
                pending_password: "PENDING_SENTINEL",
              },
            },
          },
        };
      }
      if (method === "GET") return { status: 200, body: { data: f.operation } };
      if (!path.endsWith("/result")) throw new Error("Unexpected endpoint");
      f.projections.push(body);
      if ((body as { status: string }).status === "succeeded" && f.lose) {
        f.lose = false;
        throw new Error("lost reply");
      }
      Object.assign(f.operation, body);
      return { status: 200, body: { data: f.operation } };
    });
  const invoke = (call: { address: string; action: Record<string, unknown> }) =>
    Promise.resolve().then(() => {
      assertEquals(call.address, "10.44.0.17");
      const action = call.action as {
        operation: string;
        active_password?: string;
        pending_password?: string;
        password?: string;
      };
      f.actions.push(action.operation);
      if (action.operation === "verify") {
        assertEquals(action.password, "PENDING_SENTINEL");
        return { verified: f.applied && !f.unverified };
      }
      assertEquals([action.active_password, action.pending_password], [
        "ACTIVE_SENTINEL",
        "PENDING_SENTINEL",
      ]);
      if (f.uncertain) throw new Error("PENDING_SENTINEL");
      if (f.refuse) return { error_code: "metrics.credentials_reset_failed" };
      f.applied = true;
      return { changed: true };
    });
  return {
    ...f,
    f,
    run: () =>
      metricsCredentialsReset(() => http, invoke).methods.reset.execute(
        args,
        context,
      ),
  };
}
Deno.test("reset verifies applies verifies and projects only a public result", async () => {
  const { f, run } = fixture();
  assertEquals(await run(), { status: "succeeded" });
  assertEquals(f.actions, ["verify", "apply", "verify"]);
  assertEquals(f.operation.result, { reset: true });
  assertEquals(JSON.stringify(f.projections).includes("SENTINEL"), false);
});
Deno.test("lost completion resumes with pending verification and no second password change", async () => {
  const { f, run } = fixture();
  f.lose = true;
  await assertRejects(run);
  assertEquals(await run(), { status: "succeeded" });
  assertEquals(f.actions, ["verify", "apply", "verify", "verify"]);
});
Deno.test("credential refusal and failed verification project failure and never claim a reset", async () => {
  for (const mode of ["refuse", "unverified"] as const) {
    const { f, run } = fixture();
    f[mode] = true;
    await assertRejects(
      run,
      Error,
      mode === "refuse"
        ? "metrics.credentials_reset_failed"
        : "metrics.credentials_reset_unverified",
    );
    assertEquals(f.operation.status, "failed");
    assertEquals(f.operation.result, {
      error_code: mode === "refuse"
        ? "metrics.credentials_reset_failed"
        : "metrics.credentials_reset_unverified",
    });
  }
});
Deno.test("uncertain effect sanitizes private errors and leaves pending intent recoverable", async () => {
  const { f, run } = fixture();
  f.uncertain = true;
  await assertRejects(run, Error, "operation.native_outcome_unknown");
  assertEquals(f.operation.status, "running");
  assertEquals(JSON.stringify(f.projections).includes("SENTINEL"), false);
});
Deno.test("unbound cancellation and terminal replay perform no private action", async () => {
  for (const mode of ["unbound", "cancel", "terminal"]) {
    const { f, run } = fixture();
    if (mode === "unbound") {
      f.bound = false;
      await assertRejects(run);
    }
    if (mode === "cancel") {
      f.operation.cancel_requested = true;
      assertEquals(await run(), { status: "cancelled" });
    }
    if (mode === "terminal") {
      f.operation.status = "succeeded";
      assertEquals(await run(), { status: "succeeded" });
    }
    assertEquals(f.actions, []);
    assertEquals(
      f.calls.some((path) => path.includes("/private-material")),
      false,
    );
  }
});
