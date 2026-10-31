import {
  assertEquals,
  assertRejects,
  assertThrows,
} from "jsr:@std/assert@1.0.19";
import { z } from "npm:zod@4.4.3";
import { roleCertificates } from "./role_certificates.ts";
import { roleCertificateJobs } from "./role_certificates_workflow.ts";
import type { Http } from "../../automatic-deploy/gateway.ts";

const args = {
  operation_id: 17,
  node_id: 13,
  kind: "node.role_add" as const,
  run_id: "46dff6be-7de2-4985-b891-6ebcd0897656",
};
const leaf = {
  node_id: 14,
  scope: "role-metrics" as const,
  domain: "metrics.orbit",
  version: "0123456789abcdef",
  root_hash: "a".repeat(64),
};
class Fixture {
  current = {
    id: 17,
    node_id: 13,
    kind: "node.role_add",
    status: "running",
    cancel_requested: false,
    input: { certificates: { leaves: [leaf], withdrawals: [] } },
  };
  writes: unknown[] = [];
  posts: Record<string, unknown>[] = [];
  ctx = {
    globalArgs: { gatewayUrl: "https://gateway.test", caFile: "/test/ca.pem" },
    writeResource: (_spec: string, _name: string, value: unknown) => {
      this.writes.push(value);
      return Promise.resolve({});
    },
  };
  http: Http = (method, _path, body) => {
    if (method === "POST") {
      this.posts.push(z.record(z.string(), z.unknown()).parse(body));
    }
    return Promise.resolve({ status: 200, body: { data: this.current } });
  };
  behavior = roleCertificates(() => this.http);
}

Deno.test("role certificate inventory preserves admitted public leaves and exact effect Nodes", async () => {
  const f = new Fixture();
  assertEquals(await f.behavior.methods.inventory.execute(args, f.ctx), {
    ...f.current.input.certificates,
    node_ids: [14],
  });
  assertEquals(f.posts, []);
});

Deno.test("role certificate inventory refuses changed roots and duplicate placements before effects", async () => {
  for (
    const update of [
      { id: 18 },
      { node_id: 209 },
      { kind: "node.role_remove" },
      { status: "succeeded" },
    ]
  ) {
    const f = new Fixture();
    Object.assign(f.current, update);
    await assertRejects(() =>
      f.behavior.methods.inventory.execute(args, f.ctx)
    );
    assertEquals(f.writes, []);
  }
  const f = new Fixture();
  f.current.input.certificates.leaves.push(leaf);
  await assertRejects(() => f.behavior.methods.inventory.execute(args, f.ctx));
});

Deno.test("role certificate pre-effect cancellation refuses inventory but finished effects still record", async () => {
  const f = new Fixture();
  f.current.cancel_requested = true;
  await assertRejects(() => f.behavior.methods.inventory.execute(args, f.ctx));
  await f.behavior.methods.record.execute(
    { ...args, target_node_id: 14 },
    f.ctx,
  );
  assertEquals(f.posts.length, 1);
  assertEquals(f.posts[0].status, "running");
  assertEquals(typeof f.posts[0].started_at, "string");
  assertEquals(f.posts[0].result, {
    publication_phase: { phase: "certificates_published", node_id: 14 },
  });
  assertEquals(f.posts[0].run_id, args.run_id);
  assertEquals(f.writes, [{ phase: "certificates_published", node_id: 14 }]);
});

Deno.test("role certificate recording cannot select a foreign effect Node", async () => {
  const f = new Fixture();
  await assertRejects(() =>
    f.behavior.methods.record.execute({ ...args, target_node_id: 209 }, f.ctx)
  );
  assertEquals(f.posts, []);
  assertEquals(f.writes, []);
});

Deno.test("role certificate jobs use canonical models and fixed signer, then root-only phase writes", () => {
  const generated = roleCertificateJobs("baseline_job", {
    blocked: "!steps.admit.outputs.ready",
  });
  type Job = {
    name: string;
    dependsOn: unknown;
    steps: {
      name: string;
      dependsOn?: unknown;
      target?: string;
      labels?: unknown;
      guard?: string;
      task: {
        behaviorType: string;
        behaviorName: string;
        methodName: string;
        inputs: Record<string, unknown>;
      };
    }[];
  };
  const jobs = generated.jobs as Job[];
  let previous = "baseline_job";
  for (const job of jobs) {
    assertEquals(job.dependsOn, [{
      job: previous,
      condition: { type: "succeeded" },
    }]);
    if (job.name === "role_certificates_sign_job") {
      for (const step of job.steps) {
        assertEquals(step.guard?.includes("!steps.admit.outputs.ready"), true);
        assertEquals(step.guard?.includes(".size() == 0"), true);
      }
    } else {
      assertEquals(
        job.steps[0].guard,
        "${{ !steps.admit.outputs.ready }}",
      );
    }
    previous = job.name;
  }
  assertEquals(jobs.map((job) => job.steps[0].task.methodName), [
    "inventory",
    "roleRequest",
    "prepareSignBatch",
    "rolePublish",
    "record",
    "complete",
  ]);
  for (const index of [1, 3]) {
    assertEquals(
      jobs[index].steps[0].target,
      '${{ "orbit-node-" + string(self.leaf.node_id) }}',
    );
    assertEquals(jobs[index].steps[0].labels, {
      node: "${{ string(self.leaf.node_id) }}",
    });
    assertEquals(
      jobs[index].steps[0].task.behaviorType,
      "@nckrtl/certificates",
    );
  }
  const signing = jobs[2].steps;
  assertEquals(signing.map((step) => step.task.methodName), [
    "prepareSignBatch",
    "execute",
    "signBatch",
  ]);
  for (const step of signing) {
    assertEquals(step.target, "orbit-node-209");
    assertEquals(step.labels, { node: "209" });
  }
  assertEquals(signing[0].task.behaviorName, "orbit-certificate-signing");
  assertEquals(signing[1].task.behaviorType, "command/shell");
  assertEquals(signing[1].task.behaviorName, "orbit-certificate-signing-http");
  assertEquals(signing[2].task.behaviorName, "orbit-certificate-signing");
  for (const index of [1, 2]) {
    assertEquals(signing[index].dependsOn, [{
      step: signing[index - 1].name,
      condition: { type: "succeeded" },
    }]);
  }
  assertEquals(
    signing[2].task.inputs.native_stdout,
    '${{ steps["role_certificates_sign-http"].outputs.stdout }}',
  );
  assertEquals(
    jobs[4].steps[0].target,
    '${{ "orbit-node-" + string(inputs.node_id) }}',
  );
  assertEquals(jobs[4].steps[0].labels, {
    node: "${{ string(inputs.node_id) }}",
  });
  assertEquals(jobs.at(-1)?.name, generated.lastJob);
  assertThrows(() => roleCertificateJobs("baseline", { prefix: 'bad"prefix' }));
});

Deno.test("a parent owns its aggregate certificate view without impersonating a standalone role kind", async () => {
  const f = new Fixture();
  f.current.kind = "node.add";
  await f.behavior.methods.inventory.execute(
    { ...args, kind: "node.add" },
    f.ctx,
  );
  assertEquals(f.posts, []);
  f.current.id++;
  await assertRejects(() =>
    f.behavior.methods.inventory.execute({ ...args, kind: "node.add" }, f.ctx)
  );
});
