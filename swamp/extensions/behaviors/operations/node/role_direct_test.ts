import { assertEquals, assertRejects } from "jsr:@std/assert@1.0.19";
import { BaselineStage } from "./role_direct_local.ts";
import { nodeRoleAdd } from "./role_direct.ts";
import type { RoleSnapshot } from "./role_direct_contract.ts";
import type { Http } from "../../automatic-deploy/gateway.ts";
import { Stage } from "./converge_direct_contract.ts";
import { z } from "npm:zod@4.4.3";

const args = {
  operation_id: 91,
  node_id: 13,
  kind: "node.role_add" as const,
  run_id: "a075eb23-7ac4-4487-80d1-57a038a92676",
};
const snapshot = (): RoleSnapshot => ({
  schema_version: 1,
  node_id: 13,
  agent_sha256: "a".repeat(64),
  assignment_id: 33,
  publication: { caddy_node_ids: [], php_node_ids: [], dns_node_ids: [] },
  role: "ci-runner",
  created: false,
  user: "nckrtl",
  cli: {
    user: "nckrtl",
    release: null,
    profile: "",
    skip_reason: "release_unavailable",
    plan_error: null,
  },
  prerequisites: {
    packages: ["incus"],
    releases: ["resolute"],
    apps_directory: false,
    composer_home: false,
  },
  firewall: { desired: [], remove: [], enable: false },
  footprint: {
    schema_version: 1,
    node_id: 13,
    agent_sha256: "a".repeat(64),
    force_artifacts: false,
    first_visit: false,
    artifacts: [],
    payloads: {},
    network: null,
    refusal: null,
  },
});
function fixture() {
  const calls: string[] = [];
  const posts: Record<string, unknown>[] = [];
  const writes: { name: string; value: unknown }[] = [];
  const operation = {
    id: 91,
    node_id: 13,
    kind: "node.role_add",
    input: snapshot(),
    status: "queued",
    cancel_requested: false,
  };
  const http: Http = (method, path, body) => {
    calls.push(`${method} ${path}`);
    if (body) posts.push(z.record(z.string(), z.unknown()).parse(body));
    return Promise.resolve({ status: 200, body: { data: operation } });
  };
  let effects = 0;
  const cliCalls: unknown[] = [];
  const root = nodeRoleAdd(
    () => {
      effects++;
      return Promise.resolve({ ok: true });
    },
    () => http,
    (stage) => Promise.resolve({ stage, ok: true }),
    (input) => {
      cliCalls.push(input);
      return Promise.resolve({ error_code: "cli.foreign_binary" });
    },
  );
  const ctx = {
    globalArgs: {
      gatewayUrl: "https://gateway.test",
      caFile: "/tmp/public-ca.pem",
    },
    writeResource: (_spec: string, name: string, value: unknown) => {
      writes.push({ name, value });
      return Promise.resolve({});
    },
    readResource: () => Promise.resolve({}),
  };
  return {
    root,
    ctx,
    operation,
    calls,
    posts,
    writes,
    cliCalls,
    effects: () => effects,
  };
}
async function admitted(f: ReturnType<typeof fixture>) {
  await f.root.methods.admit.execute(args, f.ctx);
  return f.writes.find((write) => write.name === "admission")!.value as {
    snapshot: RoleSnapshot;
    started_at: string;
    ready: boolean;
    refusal: null;
  };
}

Deno.test("baseline checks cancellation at its own boundary without another observation step", async () => {
  const f = fixture();
  const admission = await admitted(f);
  f.operation.cancel_requested = true;
  await f.root.methods.baseline.execute({
    ...args,
    admission,
    stage: "prerequisites",
  }, f.ctx);
  assertEquals(f.effects(), 0);
  assertEquals(f.writes.at(-1)?.value, {
    stage: "prerequisites",
    ok: false,
    error_code: "operation.cancelled",
  });
});

Deno.test("role projection records native baseline and canonical optional follow-ups", async () => {
  const f = fixture();
  const admission = await admitted(f);
  await f.root.methods.project.execute({
    ...args,
    admission,
    baseline: BaselineStage.options.map((stage) => ({
      stage,
      ok: true,
    })),
    footprints: Stage.options.map((stage) => ({ stage, ok: true })),
    cli: { error_code: "cli.foreign_binary" },
  }, f.ctx);
  assertEquals(f.posts.at(-1)?.status, "succeeded");
  const result = f.posts.at(-1)?.result as {
    follow_up: string;
    role_proven: boolean;
  };
  assertEquals(result.role_proven, true);
  assertEquals(result.follow_up, "role-cli:cli.foreign_binary");
});

Deno.test("a known baseline refusal projects the owning role failure", async () => {
  const f = fixture();
  const admission = await admitted(f);
  await assertRejects(() =>
    f.root.methods.project.execute({
      ...args,
      admission,
      baseline: [{
        stage: "prerequisites",
        ok: false,
        error_code: "role.prerequisite_failed",
      }],
      footprints: [{ stage: "agent-install", ok: true }],
      cli: null,
    }, f.ctx)
  );
  assertEquals(f.posts.at(-1)?.status, "failed");
  assertEquals(f.posts.at(-1)?.error_code, "role.prerequisite_failed");
});

Deno.test("CLI uses the canonical admitted public plan and pin", async () => {
  const f = fixture();
  const admission = await admitted(f);
  await f.root.methods.cli.execute({
    ...args,
    admission,
    baseline: BaselineStage.options.map((stage) => ({
      stage,
      ok: true,
    })),
    footprints: Stage.options.map((stage) => ({ stage, ok: true })),
  }, f.ctx);
  assertEquals(f.cliCalls, [{
    ...admission.snapshot.cli,
    agent_sha256: admission.snapshot.agent_sha256,
  }]);
});
