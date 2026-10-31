import { assertEquals, assertRejects } from "jsr:@std/assert@1";
import {
  begin,
  checkpoint,
  convergeSite,
  detach,
  dnsGrace,
  finish,
  publishRecords,
} from "./lifecycle.ts";
const args = {
  operation_id: 7,
  node_id: 17,
  kind: "analytics.enable" as const,
  run_id: "a075eb23-7ac4-4487-80d1-57a038a92676",
};
const site = {
  domain: "example.com",
  previous_domain: null,
  timezone: "UTC",
  goals: [],
};
function fixture(overrides = {}) {
  let current = {
    id: 7,
    node_id: 17,
    kind: args.kind,
    status: "queued",
    cancel_requested: false,
    input: {
      instance_id: 1,
      enabled: true,
      base_url: "http://127.0.0.1:8000",
      site,
      tracking: {
        certificates: [],
        cleanup: [],
        caddy_node_ids: [],
        dns_node_ids: [],
        router_node_ids: [],
        ingress_node_ids: [],
        public_verify_node_ids: [],
        firewall_node_ids: [],
        cleanup_caddy_node_ids: [],
      },
    },
    ...overrides,
  };
  const projected: Record<string, unknown>[] = [];
  const gateway = {
    read: () => Promise.resolve(current),
    project: (value: Record<string, unknown>) => {
      projected.push(value);
      current = { ...current, status: String(value.status) };
      return Promise.resolve({});
    },
    withPrivateMaterial: async (
      effect: (raw: Record<string, unknown>) => Promise<void>,
    ) => {
      await effect({
        plausible_credentials: {
          email: "private@example.test",
          password: "private-password-sentinel",
          apiKey: "private-api-sentinel",
        },
      });
    },
  };
  return { gateway, projected, current };
}
Deno.test("begin materializes once and returns only public planned resources", async () => {
  const f = fixture();
  const plan = await begin(args, f.gateway);
  assertEquals(plan.stopped, false);
  assertEquals(f.projected.map((p) => p.status), ["running"]);
  assertEquals(JSON.stringify(plan).includes("private"), false);
});
Deno.test("begin cancellation has no provider or host effects", async () => {
  const f = fixture({ cancel_requested: true });
  assertEquals((await begin(args, f.gateway)).stopped, true);
  assertEquals(f.projected.map((p) => p.status), ["cancelled"]);
});
Deno.test("terminal replay never repeats projection", async () => {
  const f = fixture({ status: "succeeded" });
  assertEquals((await begin(args, f.gateway)).stopped, true);
  await finish(args, true, f.gateway);
  assertEquals(f.projected, []);
});
Deno.test("foreign parent is refused before projection", async () => {
  const f = fixture({ node_id: 209 });
  await assertRejects(
    () => begin(args, f.gateway),
    Error,
    "analytics.intent_mismatch",
  );
  assertEquals(f.projected, []);
});
Deno.test("site schema and provider exceptions leave only bounded failure", async () => {
  const f = fixture({ status: "running" });
  await assertRejects(
    () =>
      convergeSite(
        args,
        f.gateway,
        (() => {
          throw new Error("private-password-sentinel");
        }) as typeof fetch,
      ),
    Error,
    "analytics.management_unavailable",
  );
  assertEquals(f.projected, []);
});
Deno.test("readiness projection is bounded and cancelled run cannot publish", async () => {
  const f = fixture({ status: "running" });
  await publishRecords(args, f.gateway);
  assertEquals(f.projected[0].result, { certificates_ready: true });
  const c = fixture({ status: "running", cancel_requested: true });
  await assertRejects(() => publishRecords(args, c.gateway));
  assertEquals(c.projected, []);
});
Deno.test("native failure projects one bounded terminal outcome and fails the run", async () => {
  const f = fixture({ status: "running" });
  await assertRejects(
    () => finish(args, false, f.gateway),
    Error,
    "analytics.change_failed",
  );
  assertEquals(f.projected[0].result, {
    error_code: "analytics.change_failed",
  });
  assertEquals(f.projected[0].status, "failed");
});
Deno.test("success projects exact site identity", async () => {
  const f = fixture({ status: "running" });
  await finish(args, true, f.gateway);
  assertEquals(f.projected[0].result, {
    ready: true,
    enabled: true,
    domain: "example.com",
    timezone: "UTC",
    readable: true,
  });
});

Deno.test("detach never reads private material or mutates the provider", async () => {
  const f = fixture({
    kind: "analytics.disable",
    status: "running",
    input: {
      instance_id: 1,
      enabled: false,
      base_url: null,
      site: null,
      tracking: {
        certificates: [],
        cleanup: [],
        caddy_node_ids: [],
        dns_node_ids: [],
        router_node_ids: [],
        ingress_node_ids: [],
        public_verify_node_ids: [],
        firewall_node_ids: [],
        cleanup_caddy_node_ids: [],
      },
    },
  });
  assertEquals(
    await detach({ ...args, kind: "analytics.disable" }, f.gateway),
    { detached: true },
  );
  assertEquals(f.projected, []);
  await finish({ ...args, kind: "analytics.disable" }, true, f.gateway);
  assertEquals(f.projected[0].result, {
    ready: true,
    enabled: false,
    domain: null,
    timezone: null,
    detached: true,
  });
});

Deno.test("activation and retirement callbacks are bounded and honour cancellation", async () => {
  const f = fixture({ status: "running" });
  await checkpoint(args, "public_verified", f.gateway);
  await checkpoint(args, "placement_cleanup", f.gateway);
  assertEquals(f.projected.map((p) => p.result), [{ public_verified: true }, {
    placement_cleanup: true,
  }]);
  const c = fixture({ status: "running", cancel_requested: true });
  await assertRejects(() => checkpoint(args, "public_verified", c.gateway));
  assertEquals(c.projected, []);
});
Deno.test("DNS grace waits only for retired placements and rechecks cancellation before cleanup", async () => {
  const f = fixture({ status: "running" });
  let waits = 0;
  await dnsGrace(args, f.gateway, undefined, () => {
    waits++;
    return Promise.resolve();
  });
  assertEquals(waits, 0);
  f.current.input.tracking.cleanup_caddy_node_ids.push(14 as never);
  await dnsGrace(args, f.gateway, undefined, () => {
    waits++;
    return Promise.resolve();
  });
  assertEquals(waits, 1);
  await assertRejects(() =>
    dnsGrace(args, f.gateway, undefined, () => {
      f.current.cancel_requested = true;
      return Promise.resolve();
    })
  );
  assertEquals(f.projected, []);
});
