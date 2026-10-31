import { assertEquals, assertRejects } from "jsr:@std/assert@1.0.19";
import { rolePublication, stripRolePublication } from "./role_publication.ts";
import { rolePublicationJobs } from "./role_publication_workflow.ts";
import type { Http } from "../../automatic-deploy/gateway.ts";

const args = {
  operation_id: 17,
  node_id: 13,
  kind: "node.role_add" as const,
  run_id: "46dff6be-7de2-4985-b891-6ebcd0897656",
};
const ctx = {
  writeResource: (_spec: string, _name: string, _value: unknown) =>
    Promise.resolve({}),
  globalArgs: { gatewayUrl: "https://gateway.orbit", caFile: "/test/ca.pem" },
};
const plan = {
  caddy_node_ids: [13, 14],
  php_node_ids: [13],
  dns_node_ids: [1, 18],
};
class Fixture {
  current = {
    id: args.operation_id,
    node_id: args.node_id,
    kind: args.kind as string,
    status: "running",
    cancel_requested: false,
    input: { publication: plan },
  };
  calls: string[] = [];
  http: Http = (method, path) => {
    this.calls.push(`${method} ${path}`);
    return Promise.resolve({ status: 200, body: { data: this.current } });
  };
  behavior = rolePublication(() => this.http);
}

Deno.test("role publication reads only the exact root admitted immutable inventory", async () => {
  const f = new Fixture();
  assertEquals(await f.behavior.methods.inventory.execute(args, ctx), plan);
  assertEquals(f.calls, [
    `GET /api/v1/direct-operations/17?run_id=${args.run_id}`,
  ]);
  assertEquals(await f.behavior.methods.complete.execute(args, ctx), {
    ready: true,
  });
});

Deno.test("role publication refuses a foreign Node kind or ended root before readiness", async () => {
  for (
    const changed of [
      { node_id: 209 },
      { id: 18 },
      { kind: "node.role_remove" },
      { status: "succeeded" },
    ]
  ) {
    const f = new Fixture();
    Object.assign(f.current, changed);
    await assertRejects(() => f.behavior.methods.complete.execute(args, ctx));
  }
});

Deno.test("role publication checks fresh cancellation before declaring dependencies ready", async () => {
  const f = new Fixture();
  f.current.cancel_requested = true;
  await assertRejects(() => f.behavior.methods.inventory.execute(args, ctx));
  f.current.cancel_requested = false;
  const signal = AbortSignal.abort();
  await assertRejects(() =>
    f.behavior.methods.complete.execute(args, { ...ctx, signal })
  );
});

Deno.test("publication fanout preserves exact participant placement including logical DNS1/18", () => {
  const generated = rolePublicationJobs("certificate_publish_job");
  type Job = {
    name: string;
    dependsOn: { job: string; condition: { type: string } }[];
    steps: {
      target?: string;
      labels?: unknown;
      forEach?: { in: string };
      task: {
        behaviorType: string;
        behaviorName: string;
        methodName: string;
        inputs: Record<string, unknown>;
      };
    }[];
  };
  const jobs = generated.jobs as Job[];
  let previous = "certificate_publish_job";
  for (const job of jobs) {
    assertEquals(job.dependsOn, [{
      job: previous,
      condition: { type: "succeeded" },
    }]);
    previous = job.name;
  }
  const effects = jobs.filter((job) => job.steps[0].forEach);
  assertEquals(
    effects.map((job) => job.steps[0].task.methodName),
    ["publishPhp", "publishCaddy", "publishFirewall", "publishDns"],
  );
  for (const job of effects) {
    const step = job.steps[0];
    assertEquals(
      step.target,
      '${{ "orbit-node-" + string(self.participant) }}',
    );
    assertEquals(step.labels, { node: "${{ string(self.participant) }}" });
    assertEquals(step.task.behaviorType, "@nckrtl/route-publication");
    assertEquals(step.task.inputs.target_node_id, "${{ self.participant }}");
    assertEquals(step.task.inputs.operation_id, "${{ inputs.operation_id }}");
    assertEquals(step.task.inputs.run_id, "${{ run.id }}");
  }
  assertEquals(
    effects.at(-1)?.steps[0].forEach?.in,
    "${{ steps.role_publication_inventory.outputs.dns_node_ids }}",
  );
  assertEquals(jobs.at(-1)?.name, generated.lastJob);
});

Deno.test("relocation target publication excludes source until the owning withdrawal gate", () => {
  const { jobs } = rolePublicationJobs("target_certificate_job", {
    prefix: "target_publication",
    excludeNodeId: "steps.admit.outputs.source_node_id",
    blocked: "!steps.admit.outputs.ready",
  });
  for (const job of jobs) {
    const step = (job.steps as Record<string, unknown>[])[0];
    assertEquals(step.guard, "${{ !steps.admit.outputs.ready }}");
    if (step.forEach) {
      assertEquals(
        String((step.forEach as { in: string }).in).includes(
          ".filter(node, node != steps.admit.outputs.source_node_id)",
        ),
        true,
      );
    }
  }
});

Deno.test("fresh public artifacts are stripped without weakening immutable role fields", () => {
  const raw = {
    publication: plan,
    assignment_id: 7,
    unexpected: "must-still-fail-strict-role-parsing",
    caddy_publication: { node_id: 13 },
    ingress_firewall: { desired: [] },
    php_publication: { configurations: {} },
    private_dns_publication: { records: "" },
  };
  assertEquals(stripRolePublication(raw), {
    publication: plan,
    assignment_id: 7,
    unexpected: "must-still-fail-strict-role-parsing",
  });
  assertEquals(Object.keys(raw).length, 7);
  assertEquals(stripRolePublication(null), null);
  assertEquals(stripRolePublication([]), []);
});
