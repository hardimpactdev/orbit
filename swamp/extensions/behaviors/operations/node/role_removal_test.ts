import { assertEquals, assertRejects } from "jsr:@std/assert@1.0.19";
import { roleRemoval } from "./role_removal.ts";
import type { Http } from "../../automatic-deploy/gateway.ts";
import {
  roleRemovalJobs,
  roleRemovalWorkflow,
} from "./role_removal_workflow.ts";

const args = {
  operation_id: 17,
  node_id: 13,
  kind: "node.role_remove" as const,
  run_id: "405c68f5-e089-46db-bbeb-03a458b1c6c4",
};
function fixture() {
  const plan = {
    schema_version: 1 as const,
    node_id: 13,
    assignment_id: 8,
    role: "ci-runner" as const,
    agent_sha256: "a".repeat(64),
    user: "orbit",
    purge_data: false,
    offline: false as const,
    publication: { caddy_node_ids: [], php_node_ids: [], dns_node_ids: [] },
    certificates: {
      leaves: [],
      withdrawals: [] as { node_id: number; scope: string }[],
    },
    metrics_fleet: null,
    runtime: null,
    caddy: null,
    firewall: null,
    public_ssh: null,
  };
  const current = {
    id: 17,
    node_id: 13,
    kind: "node.role_remove",
    status: "running",
    cancel_requested: false,
    input: { ...plan, role_removal: plan } as Record<string, unknown>,
  };
  const posts: Record<string, unknown>[] = [],
    effects: string[] = [],
    waits: number[] = [];
  const http: Http = (method, _path, body) => {
    if (method === "POST") posts.push(body as Record<string, unknown>);
    return Promise.resolve({ status: 200, body: { data: current } });
  };
  const ctx = {
    globalArgs: { gatewayUrl: "https://gateway.test", caFile: "/ca.pem" },
    writeResource: () => Promise.resolve({}),
  };
  const behavior = roleRemoval(() => http, (_plan, stage, _signal, node) => {
    effects.push(`${node}:${stage}`);
    return Promise.resolve({ ok: true });
  }, (seconds) => {
    waits.push(seconds);
    return Promise.resolve();
  });
  return { plan, current, posts, effects, waits, ctx, behavior };
}
Deno.test("role removal admission reads intent and never executes a host effect", async () => {
  const f = fixture();
  await f.behavior.methods.admit.execute(args, f.ctx);
  assertEquals(f.effects, []);
  assertEquals(f.posts[0].status, "running");
  await f.behavior.methods.stage.execute({
    ...args,
    target_node_id: 13,
    stage: "public-ssh",
  }, f.ctx);
  assertEquals(f.effects, ["13:public-ssh"]);
});
Deno.test("role cleanup refuses foreign root, changed participant, terminal and cancellation before effects", async () => {
  for (const drift of ["root", "participant", "terminal", "cancel"]) {
    const f = fixture();
    if (drift === "root") f.current.id = 99;
    if (drift === "participant") f.current.input.node_id = 99;
    if (drift === "terminal") f.current.status = "succeeded";
    if (drift === "cancel") f.current.cancel_requested = true;
    await assertRejects(() =>
      f.behavior.methods.stage.execute({
        ...args,
        target_node_id: 13,
        stage: "runtime",
      }, f.ctx)
    );
    assertEquals(f.effects, []);
  }
});
Deno.test("relocation cleanup records source completion under its original root and observes DNS grace", async () => {
  const f = fixture();
  f.current.kind = "node.role_relocate";
  f.current.input.source_removal = { ...f.plan, node_id: 9 };
  f.current.input.node_id = 9;
  f.current.input.withdrawal_grace_seconds = 31;
  const root = { ...args, kind: "node.role_relocate" as const };
  await f.behavior.methods.grace.execute(root, f.ctx);
  assertEquals(f.waits, [31]);
  await f.behavior.methods.stage.execute({
    ...root,
    target_node_id: 9,
    stage: "runtime",
  }, f.ctx);
  assertEquals(f.effects, ["9:runtime"]);
  await f.behavior.methods.complete.execute(root, f.ctx);
  assertEquals(f.posts.at(-1)?.status, "running");
  assertEquals(f.posts.at(-1)?.result, { source_completed: true });
});
Deno.test("Metrics certificate withdrawal runs only on its admitted Gateway participant", async () => {
  const f = fixture();
  f.plan.certificates.withdrawals = [{ node_id: 14, scope: "role-metrics" }];
  f.current.input.node_id = 14;
  await f.behavior.methods.withdraw.execute(args, f.ctx);
  assertEquals(f.posts.at(-1)?.result, {
    publication_phase: { phase: "withdrawal_started", node_id: 14 },
  });
  await f.behavior.methods.stage.execute({
    ...args,
    target_node_id: 14,
    stage: "certificates",
  }, f.ctx);
  assertEquals(f.effects, ["14:certificates"]);
  await assertRejects(() =>
    f.behavior.methods.stage.execute({
      ...args,
      target_node_id: 14,
      stage: "runtime",
    }, f.ctx)
  );
});
Deno.test("source chain publishes DNS before grace then Caddy before runtime and preserves exact placements", () => {
  const chain = roleRemovalJobs("target_ready_job");
  const names = chain.jobs.map((job) => String(job.name));
  assertEquals(
    names.indexOf("source_dns_dns_job") < names.indexOf("source_grace_job"),
    true,
  );
  assertEquals(
    names.indexOf("source_grace_job") <
      names.indexOf("source_publication_caddy_job"),
    true,
  );
  assertEquals(
    names.indexOf("source_publication_caddy_job") <
      names.indexOf("source_runtime_job"),
    true,
  );
  const runtime = chain.jobs.find((job) =>
    job.name === "source_runtime_job"
  )! as { steps: { target: string; labels: { node: string } }[] };
  assertEquals(
    runtime.steps[0].labels,
    { node: "${{ string(steps.source_inventory.outputs.node_id) }}" },
  );
  assertEquals(
    runtime.steps[0].labels.node,
    "${{ string(steps.source_inventory.outputs.node_id) }}",
  );
  assertEquals(
    roleRemovalWorkflow().jobs.some((job) =>
      JSON.stringify(job).includes('"type":"workflow"')
    ),
    false,
  );
});

Deno.test("failed removal invokes the owning result projection even after cancellation", async () => {
  for (const cancelled of [false, true]) {
    const f = fixture();
    f.current.cancel_requested = cancelled;
    await f.behavior.methods.fail.execute(args, f.ctx);
    assertEquals(f.posts.at(-1)?.status, cancelled ? "cancelled" : "failed");
    assertEquals(f.posts.at(-1)?.result, {
      removed: false,
      failed_step: "cleanup",
      error_code: cancelled ? "operation.cancelled" : "node_role.remove_failed",
    });
    assertEquals(f.effects, []);
  }
});
